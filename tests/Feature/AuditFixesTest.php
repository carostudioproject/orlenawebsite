<?php

namespace Tests\Feature;

use App\Actions\Integrations\ErzapSync;
use App\Actions\Payments\ApplyPaymentStatus;
use App\Actions\Payments\ReconcilePayment;
use App\Models\Category;
use App\Models\Customer;
use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\PaymentGateways;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fixes from the second-reviewer audit (Codex): payment, Erzap, upload and header safeguards. */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
        config(['services.erzap.enabled' => false]);
    }

    private function order(array $attributes = []): Order
    {
        $category = Category::firstOrCreate(['name' => 'Brownies']);
        $product = Product::create(['category_id' => $category->id, 'sku' => 'SKU-'.Str::random(5), 'barcode' => 'BC-'.Str::random(6), 'name' => 'Berry', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::firstOrCreate(['code' => 'T'], ['name' => 'Test Outlet', 'address' => 'Jl', 'is_active' => true, 'delivers' => true]);
        $customer = Customer::create(['name' => 'Sinta', 'whatsapp' => '6281234567890', 'email' => null]);
        $order = Order::create([
            'order_code' => 'ORL-300101-'.strtoupper(Str::random(10)), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64),
            'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name, 'fulfillment_method' => 'pickup',
            'requested_date' => '2030-01-05', 'subtotal' => 160000, 'delivery_fee' => 0, 'total' => 160000, 'order_status' => 'pending_review', 'payment_status' => 'not_created',
            ...$attributes,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => 'Berry', 'category_snapshot' => 'Brownies', 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 80000, 'quantity' => 2, 'subtotal' => 160000]);

        return $order;
    }

    public function test_demo_payments_are_blocked_on_production_unless_allowed(): void
    {
        config(['services.doku.mode' => 'demo', 'services.doku.enabled' => false, 'services.doku.allow_demo' => false]);
        $this->app['env'] = 'production';
        $this->assertSame([false, 'off', false], [PaymentGateways::demo(), PaymentGateways::mode(), PaymentGateways::enabled()]);
        // Even with DOKU switched on, "off" never falls back to a demo gateway.
        config(['services.doku.enabled' => true]);
        $this->assertFalse(PaymentGateways::enabled());
        $order = $this->order(['order_status' => 'confirmed', 'payment_status' => 'pending']);
        // Outside the testing environment CSRF applies again; the point here is the 404.
        $this->withoutMiddleware(ValidateCsrfToken::class)->post('/bayar/'.$order->order_code.'/simulasi')->assertNotFound();

        // A demo server opts in explicitly.
        config(['services.doku.allow_demo' => true]);
        $this->assertSame([true, 'demo', true], [PaymentGateways::demo(), PaymentGateways::mode(), PaymentGateways::enabled()]);
    }

    public function test_rescheduling_keeps_the_payment_deadline_before_the_new_payment_close(): void
    {
        config(['services.doku.enabled' => false, 'services.doku.mode' => 'demo', 'services.doku.methods' => ['va_bca', 'qris']]);
        Setting::put(['po.payment_close_time' => '19:00']);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 18:00:00', 'Asia/Makassar'));
        $order = $this->order();
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasNoErrors();
        $this->assertSame('2030-01-01 20:00', $order->fresh()->payment_due_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));

        // Moved to 2 January: its H-1 payment close (set to 1 January 19:00 WITA here) comes before the 2-hour deadline.
        $this->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 0, 'note' => 'Earlier', 'requested_date' => '2030-01-02', 'requested_time' => '10:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2030-01-01 19:00', $order->fresh()->payment_due_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));
    }

    public function test_the_mapping_page_rejects_duplicate_and_spaced_barcodes(): void
    {
        $first = $this->order()->items()->first()->product;
        $second = $this->order()->items()->first()->product;
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put('/admin/integrations/mapping', ['products' => [['id' => $second->id, 'barcode' => strtolower($first->barcode)]]])
            ->assertSessionHasErrors('products.0.barcode');
        $this->put('/admin/integrations/mapping', ['products' => [['id' => $second->id, 'barcode' => '123 456']]])->assertSessionHasErrors('products.0.barcode');
        $this->put('/admin/integrations/mapping', ['products' => [['id' => $first->id, 'barcode' => 'X1'], ['id' => $second->id, 'barcode' => 'x1']]])
            ->assertSessionHasErrors('products.1.barcode');
        // Swapping two barcodes in one save is fine.
        $this->put('/admin/integrations/mapping', ['products' => [['id' => $first->id, 'barcode' => $second->barcode], ['id' => $second->id, 'barcode' => $first->barcode]]])
            ->assertSessionHasNoErrors();
    }

    public function test_made_up_api_tokens_do_not_get_their_own_rate_limit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/categories', ['Authorization' => 'Bearer random-'.$i])->assertOk();
        }
        $this->getJson('/api/v1/categories', ['Authorization' => 'Bearer random-again'])->assertStatus(429);
    }

    public function test_images_with_huge_dimensions_are_refused_before_decoding(): void
    {
        Storage::fake('public');
        $product = $this->order()->items()->first()->product;
        // A PNG header claiming 20000 × 20000 pixels: tiny file, but GD would need ~1.6 GB to decode it.
        $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 20000, 20000)."\x08\x06\x00\x00\x00".pack('N', 0);
        $bomb = UploadedFile::fake()->createWithContent('big.png', $header.str_repeat("\0", 64));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->put('/admin/products/'.$product->id, [
            'name' => 'Berry', 'sku' => $product->sku, 'category_id' => $product->category_id, 'price' => 80000, 'is_active' => true, 'upload' => $bomb,
        ])->assertSessionHasErrors('upload');
        $this->assertNull($product->fresh()->image);
    }

    public function test_erzap_only_marks_and_offers_paid_orders_that_are_not_cancelled(): void
    {
        config(['services.erzap.inbound_token' => 'inbound']);
        $headers = ['X-Erzap-Token' => 'inbound'];
        $unpaid = $this->order(['order_status' => 'confirmed', 'payment_status' => 'pending']);
        $this->postJson('/web_service/update_status_pesanan/new', ['update_status_shopping_cart' => 5, 'kode_shopping_cart' => $unpaid->order_code], $headers)
            ->assertOk()->assertJson(['status' => 0]);
        $this->assertDatabaseMissing('integration_syncs', ['subject_id' => $unpaid->id]);

        // Paid, then cancelled before it was sent: neither the pull nor the push sends it.
        $cancelled = $this->order(['order_status' => 'cancelled', 'payment_status' => 'paid']);
        ErzapSync::queue($cancelled);
        $paid = $this->order(['order_status' => 'confirmed', 'payment_status' => 'paid']);
        ErzapSync::queue($paid);
        $carts = $this->postJson('/web_service/check_pesanan/new', ['check_pesanan' => true], $headers)->json('data.shopping_carts');
        $this->assertSame([$paid->order_code], array_column($carts, 'kode'));
        $sync = IntegrationSync::where('subject_id', $cancelled->id)->sole();
        $this->assertSame('skipped', app(ErzapSync::class)->run($sync));
        $this->assertSame('The order was cancelled.', $sync->fresh()->last_error);
    }

    private function payment(Order $order, int $attempt, int $amount, string $status, string $provider = 'doku_va', array $extra = []): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'attempt' => $attempt, 'provider' => $provider, 'provider_order_id' => $order->order_code.'-P'.$attempt,
            'amount' => $amount, 'status' => $status, 'open_order_id' => $status === 'pending' ? $order->id : null, 'expires_at' => now()->addHour(), ...$extra,
        ]);
    }

    private function success(Payment $payment): string
    {
        return app(ApplyPaymentStatus::class)->handle(['order_id' => $payment->provider_order_id, 'transaction_status' => 'SUCCESS', 'gross_amount' => $payment->amount.'.00',
            'payment_type' => 'VA-BCA', 'transaction_date' => now()->toIso8601String()], 'test');
    }

    public function test_an_older_link_paid_after_the_total_went_up_does_not_settle_the_order(): void
    {
        // Link 1 was for Rp 150.000; the delivery fee then raised the total to Rp 160.000 and link 1 expired.
        $order = $this->order(['order_status' => 'confirmed', 'payment_status' => 'expired']);
        $old = $this->payment($order, 1, 150000, 'expired');
        $this->assertSame('underpaid', $this->success($old));
        $this->assertSame(['paid', 'expired'], [$old->fresh()->status, $order->fresh()->payment_status]);
        $this->assertDatabaseMissing('integration_syncs', ['subject_id' => $order->id]);

        // Staff record only the difference; then the order is paid.
        $this->actingAs(User::factory()->create(['role' => 'staff']))->post('/admin/orders/'.$order->id.'/payments/manual', ['method' => 'transfer'])->assertSessionHasNoErrors();
        $this->assertSame([10000, 'paid'], [Payment::where('provider', 'manual')->sole()->amount, $order->fresh()->payment_status]);
    }

    public function test_paying_one_link_closes_the_other_open_link_and_one_refund_keeps_it_paid(): void
    {
        $order = $this->order(['order_status' => 'confirmed', 'payment_status' => 'pending']);
        $late = $this->payment($order, 1, 160000, 'expired', 'demo');
        $open = $this->payment($order, 2, 160000, 'pending', 'demo');
        $this->assertSame('applied', $this->success($late));
        $this->assertSame(['paid', 'cancelled', null], [$order->fresh()->payment_status, $open->fresh()->status, $open->fresh()->open_order_id]);

        // Paid twice (the closed link was paid anyway): refunding one keeps the order paid.
        $this->success($open);
        app(ApplyPaymentStatus::class)->handle(['order_id' => $open->provider_order_id, 'transaction_status' => 'REFUNDED', 'gross_amount' => '160000.00', 'transaction_date' => 'r1'], 'test');
        $this->assertSame(['refunded', 'paid'], [$open->fresh()->status, $order->fresh()->payment_status]);
    }

    public function test_the_scheduler_checks_open_and_recently_closed_links(): void
    {
        $order = $this->order(['order_status' => 'confirmed', 'payment_status' => 'pending']);
        $open = $this->payment($order, 1, 160000, 'pending');
        $recentlyExpired = $this->payment($order, 2, 160000, 'expired');
        $checkedMoments = $this->payment($order, 3, 160000, 'expired', 'doku_va', ['checked_at' => now()->subMinutes(5)]);
        $manual = $this->payment($order, 4, 160000, 'cancelled', 'manual');
        $old = $this->payment($order, 5, 160000, 'cancelled');
        Payment::whereKey($old->id)->toBase()->update(['updated_at' => now()->subDays(3)]);

        $checked = [];
        $this->mock(ReconcilePayment::class, function ($mock) use (&$checked) {
            $mock->shouldReceive('handle')->andReturnUsing(function (Payment $payment) use (&$checked) {
                $checked[] = $payment->id;

                return 'not_started';
            });
        });
        $this->artisan('payments:reconcile')->assertSuccessful();
        sort($checked);
        $this->assertSame([$open->id, $recentlyExpired->id], $checked);
    }

    public function test_an_erzap_send_in_progress_is_not_sent_twice(): void
    {
        config(['services.erzap.enabled' => true, 'services.erzap.base_url' => 'https://erzap.test:4443', 'services.erzap.token' => 'erzap-secret',
            'services.erzap.sales_user_id' => '8', 'services.erzap.default_outlet_id' => '1']);
        Http::fake(['erzap.test:4443/*' => Http::response(['status' => '1', 'message' => 'Sukses menyimpan pesanan penjualan'])]);
        $order = $this->order(['order_status' => 'confirmed', 'payment_status' => 'paid']);
        ErzapSync::queue($order);
        $sync = IntegrationSync::sole();

        // Claimed by another run moments ago: left alone, nothing sent.
        $sync->update(['status' => 'sending', 'next_attempt_at' => now()->addMinute()]);
        $this->assertSame('sending', app(ErzapSync::class)->run($sync));
        $this->assertCount(0, app(ErzapSync::class)->due());
        Http::assertNothingSent();

        // That run died: once its claim expires the row is picked up and sent.
        $this->travel(2)->minutes();
        $this->assertSame([$sync->id], app(ErzapSync::class)->due()->pluck('id')->all());
        $this->assertSame('synced', app(ErzapSync::class)->run($sync));
        Http::assertSentCount(1);
    }

    public function test_every_response_carries_basic_security_headers(): void
    {
        $this->get('/admin/login')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('Strict-Transport-Security');
        // A stricter policy set by the route is kept.
        $order = $this->order(['order_status' => 'confirmed', 'payment_status' => 'pending']);
        $this->get('/bayar/'.$order->order_code)->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
