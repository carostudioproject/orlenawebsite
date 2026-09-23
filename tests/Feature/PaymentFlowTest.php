<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'SB-Mid-server-TEST';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.midtrans.server_key' => self::KEY, 'services.midtrans.is_production' => false]);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
    }

    private function order(array $overrides = []): Order
    {
        $category = Category::firstOrCreate(['name' => 'FULLSIZE TEST']);
        $product = Product::create(['category_id' => $category->id, 'sku' => 'SKU-'.Str::random(6), 'name' => 'Test Brownie', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::firstOrCreate(['code' => 'TEST'], ['name' => 'Test Outlet', 'address' => 'Address', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer Test', 'whatsapp' => '6281234567890', 'email' => null]);
        $order = Order::create([
            'order_code' => 'ORL-300101-'.strtoupper(Str::random(10)), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64),
            'request_hash' => str_repeat('b', 64), 'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name,
            'fulfillment_method' => 'delivery', 'requested_date' => '2030-01-03', 'delivery_address' => 'Destination',
            'subtotal' => 160000, 'delivery_fee' => 15000, 'total' => 175000, 'order_status' => 'pending_review', 'payment_status' => 'not_created', ...$overrides,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name_snapshot' => 'Test Brownie', 'category_snapshot' => 'FULLSIZE TEST', 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 80000, 'quantity' => 2, 'subtotal' => 160000,
        ]);
        DB::table('order_status_histories')->insert(['order_id' => $order->id, 'from_status' => null, 'to_status' => $order->order_status, 'created_at' => now()]);

        return $order;
    }

    /** Queued responses are used first; afterwards Snap succeeds and Get Status reports no transaction. */
    private function fakeMidtrans(array $snap = [], array $status = []): void
    {
        $snapSequence = Http::sequence($snap)->whenEmpty(Http::response(['token' => 'snap-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token'], 201));
        $statusSequence = Http::sequence($status)->whenEmpty(Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404));
        Http::fake([
            '*/snap/v1/transactions/*/cancel' => Http::response(['canceled_at' => '2030-01-01T04:00:00Z']),
            '*/snap/v1/transactions' => $snapSequence,
            '*/status' => $statusSequence,
        ]);
    }

    private function notify(Payment $payment, string $status, array $extra = [])
    {
        $data = ['order_id' => $payment->provider_order_id, 'status_code' => '200', 'gross_amount' => $payment->amount.'.00',
            'transaction_status' => $status, 'transaction_id' => 'trx-'.$payment->id, 'payment_type' => 'qris', 'fraud_status' => 'accept', ...$extra];
        $data['signature_key'] ??= hash('sha512', $data['order_id'].$data['status_code'].$data['gross_amount'].self::KEY);

        return $this->postJson('/webhooks/midtrans', $data);
    }

    private function confirm(Order $order): void
    {
        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => $order->fresh()->review_version])->assertSessionHasNoErrors();
    }

    public function test_confirmation_creates_one_midtrans_link_capped_by_twenty_four_hours(): void
    {
        $this->fakeMidtrans();
        $order = $this->order();
        $staff = User::factory()->create();
        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertRedirect('/admin/login');
        $this->actingAs($staff);
        $this->confirm($order);

        $order->refresh();
        $this->assertSame(['confirmed', 'pending'], [$order->order_status, $order->payment_status]);
        $payment = Payment::sole();
        $this->assertSame([$order->order_code.'-P1', 'pending', 175000], [$payment->provider_order_id, $payment->status, $payment->amount]);
        $this->assertSame('2030-01-02 12:00:00', $payment->expires_at->format('Y-m-d H:i:s'));
        Http::assertSent(function (HttpRequest $request) use ($order) {
            $items = collect($request['item_details']);

            return $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::KEY.':'))
                && $request['transaction_details'] === ['order_id' => $order->order_code.'-P1', 'gross_amount' => 175000]
                && $request['expiry'] === ['start_time' => '2030-01-01 11:00:00 +0700', 'unit' => 'minute', 'duration' => 1440]
                && $items->sum(fn ($item) => $item['price'] * $item['quantity']) === 175000;
        });

        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasErrors('payment');
        $this->post('/admin/orders/'.$order->id.'/payments/retry')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 1);
        $this->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->has('payments', 1)
            ->missing('payments.0.snap_token')->where('payments.0.payment_url', $payment->payment_url))->assertDontSee(self::KEY);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'from_status' => 'pending_review', 'to_status' => 'confirmed', 'actor_id' => $staff->id]);
    }

    public function test_link_expiry_stops_at_cutoff_and_closed_dates_must_be_rescheduled(): void
    {
        $this->fakeMidtrans();
        $this->actingAs(User::factory()->create());
        $order = $this->order(['requested_date' => '2030-01-02']);
        $this->confirm($order);
        $this->assertSame('2030-01-01 18:00:00', Payment::sole()->expires_at->format('Y-m-d H:i:s'));

        $this->travelTo(CarbonImmutable::parse('2030-01-01 17:50:00', 'Asia/Makassar'));
        $late = $this->order(['requested_date' => '2030-01-02']);
        $this->post('/admin/orders/'.$late->id.'/confirm', ['review_version' => 0])->assertSessionHasErrors('payment');
        $this->assertSame('pending_review', $late->fresh()->order_status);
        $this->post('/admin/orders/'.$late->id.'/review', ['review_version' => 0, 'delivery_fee' => 15000, 'note' => 'Moved', 'requested_date' => '2030-01-01'])->assertSessionHasErrors('requested_date');
        $this->post('/admin/orders/'.$late->id.'/review', ['review_version' => 0, 'delivery_fee' => 15000, 'note' => 'Customer agreed', 'requested_date' => '2030-01-03', 'requested_time' => '10:00'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_reviews', ['order_id' => $late->id, 'previous_schedule' => '2030-01-02', 'schedule' => '2030-01-03 10:00']);
        $this->confirm($late->fresh());
        $this->assertSame('pending', $late->fresh()->payment_status);
    }

    public function test_midtrans_failure_keeps_order_confirmed_and_retry_creates_a_new_attempt(): void
    {
        $this->fakeMidtrans([Http::response(['error_messages' => ['Access denied']], 401)]);
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasErrors('payment');
        $this->assertSame(['confirmed', 'not_created'], [$order->fresh()->order_status, $order->fresh()->payment_status]);
        $this->assertSame('creation_failed', Payment::sole()->status);
        $this->assertStringNotContainsString(self::KEY, Payment::sole()->last_error);

        $this->post('/admin/orders/'.$order->id.'/payments/retry')->assertSessionHasNoErrors();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame([1 => 'creation_failed', 2 => 'pending'], Payment::orderBy('attempt')->pluck('status', 'attempt')->all());
    }

    public function test_verified_webhooks_are_idempotent_and_never_regress_paid_orders(): void
    {
        $this->fakeMidtrans();
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->confirm($order);
        $payment = Payment::sole();

        $this->notify($payment, 'settlement', ['signature_key' => 'forged'])->assertForbidden();
        $this->notify($payment, 'settlement', ['gross_amount' => '1000.00'])->assertOk()->assertJson(['status' => 'amount_mismatch']);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->notify($payment, 'settlement')->assertOk()->assertJson(['status' => 'applied']);
        $this->notify($payment, 'settlement')->assertOk()->assertJson(['status' => 'duplicate']);
        $this->notify($payment, 'pending', ['status_code' => '201'])->assertOk()->assertJson(['status' => 'ignored']);
        $this->notify($payment, 'expire', ['status_code' => '407'])->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(['paid', 'confirmed'], [$order->fresh()->payment_status, $order->fresh()->order_status]);
        $this->assertNull($payment->fresh()->open_order_id);
        $this->assertSame('qris', $payment->fresh()->payment_type);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.status_changed')->count());
        // mismatch, settlement, late pending, late expire; the duplicate settlement is not stored twice.
        $this->assertSame(4, DB::table('payment_events')->count());
        $this->postJson('/webhooks/midtrans', ['order_id' => 'unknown', 'status_code' => '200', 'gross_amount' => '1.00', 'transaction_status' => 'settlement',
            'signature_key' => hash('sha512', 'unknown2001.00'.self::KEY)])->assertOk()->assertJson(['status' => 'unknown_payment']);
        $this->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 1, 'note' => 'Too late'])->assertSessionHasErrors('review');
    }

    public function test_expired_link_requires_reason_for_a_new_link_and_uses_latest_total(): void
    {
        $this->fakeMidtrans();
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->confirm($order);
        $this->post('/admin/orders/'.$order->id.'/payments/renew', ['reason' => 'Too early'])->assertSessionHasErrors('payment');
        $this->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 1, 'note' => 'Locked'])->assertSessionHasErrors('review');
        $this->notify(Payment::sole(), 'expire', ['status_code' => '407'])->assertJson(['status' => 'applied']);
        $this->assertSame('expired', $order->fresh()->payment_status);

        $this->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 20000, 'note' => 'New courier quote'])->assertSessionHasNoErrors();
        $this->post('/admin/orders/'.$order->id.'/payments/renew', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/admin/orders/'.$order->id.'/payments/renew', ['reason' => 'Customer asked again'])->assertSessionHasNoErrors();
        $latest = Payment::where('attempt', 2)->sole();
        $this->assertSame([180000, 'pending', 'Customer asked again'], [$latest->amount, $latest->status, $latest->reason]);
        $this->assertSame('pending', $order->fresh()->payment_status);
        // A late event for the old link never overwrites the newest link's status.
        $this->notify(Payment::where('attempt', 1)->sole(), 'cancel', ['status_code' => '202'])->assertJson(['status' => 'ignored']);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_snap_links_without_a_transaction_expire_through_reconciliation(): void
    {
        $notFound = fn () => Http::response(['status_code' => '404'], 404);
        $this->fakeMidtrans([], [$notFound(), $notFound(), Http::response([
            'order_id' => 'ORL-300101-OTHER00001-P1', 'status_code' => '200', 'gross_amount' => '175000.00',
            'transaction_status' => 'settlement', 'transaction_id' => 'trx-x', 'fraud_status' => 'accept',
        ])]);
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->confirm($order);
        $payment = Payment::sole();
        $this->post('/admin/orders/'.$order->id.'/payments/'.$payment->id.'/check')->assertSessionHas('success', 'The customer has not chosen a payment method in Midtrans yet.');
        $this->assertSame('pending', $payment->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2030-01-02 12:10:00', 'Asia/Makassar'));
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(['expired', 'expired'], [$payment->fresh()->status, $order->fresh()->payment_status]);

        $other = $this->order(['order_code' => 'ORL-300101-OTHER00001']);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
        $this->confirm($other);
        $otherPayment = Payment::where('order_id', $other->id)->sole();
        $this->post('/admin/orders/'.$other->id.'/payments/'.$otherPayment->id.'/check')->assertSessionHas('success', 'Payment status updated from Midtrans.');
        $this->assertSame('paid', $other->fresh()->payment_status);
        $this->post('/admin/orders/'.$order->id.'/payments/'.$otherPayment->id.'/check')->assertNotFound();
    }

    public function test_fulfillment_follows_payment_and_method_rules(): void
    {
        $this->fakeMidtrans();
        $this->actingAs(User::factory()->create());
        $order = $this->order(['fulfillment_method' => 'pickup', 'delivery_fee' => 0, 'total' => 160000, 'delivery_address' => null]);
        $this->confirm($order);
        $status = fn (string $from, string $to) => $this->post('/admin/orders/'.$order->id.'/status', ['from' => $from, 'to' => $to]);
        $status('confirmed', 'processing')->assertSessionHasErrors('status');
        $this->notify(Payment::sole(), 'settlement');
        $status('confirmed', 'ready')->assertSessionHasErrors('status');
        $status('confirmed', 'processing')->assertSessionHasNoErrors();
        $status('confirmed', 'processing')->assertSessionHasErrors('status');
        $status('processing', 'ready')->assertSessionHasNoErrors();
        $status('ready', 'delivering')->assertSessionHasErrors('status');
        $status('ready', 'completed')->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->order_status);
        $this->assertSame(['pending_review', 'confirmed', 'processing', 'ready', 'completed'], DB::table('order_status_histories')->where('order_id', $order->id)->orderBy('id')->pluck('to_status')->all());
        $this->post('/admin/orders/'.$order->id.'/cancel', ['from' => 'completed', 'cancel_reason' => 'No'])->assertSessionHasErrors('status');
    }

    public function test_cancellation_closes_open_links_and_paid_orders_need_an_admin(): void
    {
        $this->fakeMidtrans();
        $staff = User::factory()->create();
        $this->actingAs($staff);
        $open = $this->order();
        $this->confirm($open);
        $this->post('/admin/orders/'.$open->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => ''])->assertSessionHasErrors('cancel_reason');
        $this->post('/admin/orders/'.$open->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Customer changed mind'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'cancelled'], [$open->fresh()->order_status, $open->fresh()->payment_status]);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions/snap-token/cancel');
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $open->id, 'to_status' => 'cancelled', 'note' => 'Customer changed mind']);
        // Money received after cancellation is still recorded so staff can refund it.
        $this->notify(Payment::where('order_id', $open->id)->sole(), 'settlement')->assertJson(['status' => 'applied']);
        $this->assertSame('paid', $open->fresh()->payment_status);

        $paid = $this->order(['order_code' => 'ORL-300101-PAID000001']);
        $this->confirm($paid);
        $this->notify(Payment::where('order_id', $paid->id)->sole(), 'settlement');
        $this->post('/admin/orders/'.$paid->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Out of stock'])->assertSessionHasErrors('status');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/orders/'.$paid->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Out of stock'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'paid'], [$paid->fresh()->order_status, $paid->fresh()->payment_status]);
        $this->notify(Payment::where('order_id', $paid->id)->sole(), 'refund')->assertJson(['status' => 'applied']);
        $this->assertSame('refunded', $paid->fresh()->payment_status);
    }

    public function test_disabled_staff_cannot_create_payments(): void
    {
        $this->fakeMidtrans();
        $order = $this->order();
        $this->actingAs(User::factory()->create(['is_active' => false]))->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertRedirect('/admin/login');
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }
}
