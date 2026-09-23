<?php

namespace Tests\Feature;

use App\Actions\Integrations\ErzapSync;
use App\Actions\Payments\ApplyPaymentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErzapAndApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Outlet $outlet;

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
        $category = Category::create(['name' => 'Brownies', 'is_active' => true]);
        $this->product = Product::create(['category_id' => $category->id, 'variant' => 'Fullsize', 'sku' => 'B-F', 'name' => 'Berry', 'price' => 80000, 'is_active' => true]);
        $this->outlet = Outlet::create(['code' => 'O', 'name' => 'Test Outlet', 'address' => 'Jl', 'is_active' => true]);
        config(['services.erzap' => [...config('services.erzap'), 'enabled' => false, 'base_url' => null, 'token' => null, 'webhook_token' => null], 'services.api.tokens' => []]);
    }

    /** A confirmed order with a pending link, then Midtrans settlement. */
    private function paidOrder(): Order
    {
        $customer = Customer::create(['name' => 'Sinta', 'whatsapp' => '6281234567890', 'email' => 'sinta@example.test']);
        $order = Order::create([
            'order_code' => 'ORL-300101-'.strtoupper(Str::random(10)), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64),
            'customer_id' => $customer->id, 'outlet_id' => $this->outlet->id, 'outlet_name_snapshot' => 'Test Outlet', 'fulfillment_method' => 'delivery',
            'requested_date' => '2030-01-03', 'delivery_address' => 'Secret address 1', 'subtotal' => 160000, 'delivery_fee' => 15000, 'total' => 175000,
            'order_status' => 'confirmed', 'payment_status' => 'pending',
        ]);
        $order->items()->create(['product_id' => $this->product->id, 'product_name_snapshot' => 'Berry', 'category_snapshot' => 'Brownies', 'variant_snapshot' => 'Fullsize',
            'sku_snapshot' => 'B-F', 'unit_price_snapshot' => 80000, 'quantity' => 2, 'subtotal' => 160000]);
        $payment = Payment::create(['order_id' => $order->id, 'attempt' => 1, 'provider_order_id' => $order->order_code.'-P1', 'open_order_id' => $order->id, 'amount' => 175000, 'status' => 'pending']);
        $this->assertSame('applied', app(ApplyPaymentStatus::class)->handle([
            'order_id' => $payment->provider_order_id, 'transaction_status' => 'settlement', 'gross_amount' => '175000.00', 'status_code' => '200', 'transaction_id' => 'trx-1', 'payment_type' => 'qris',
        ], 'webhook'));

        return $order->fresh();
    }

    public function test_paid_orders_queue_for_erzap_and_wait_for_config_and_mapping(): void
    {
        // Erzap fails once, then accepts the retry and the later cancellation.
        Http::fake(['erzap.test/*' => Http::sequence()->push(['message' => 'down'], 500)->push(['id' => 'ERZ-123'], 201)->push(['id' => 'ERZ-123'])]);
        $order = $this->paidOrder();
        $sync = IntegrationSync::sole();
        $this->assertSame(['erzap', 'transaction.push', 'pending', $order->id], [$sync->provider, $sync->type, $sync->status, $sync->subject_id]);

        // Queuing the same order again never creates a second sync.
        ErzapSync::queue($order, 'transaction.push');
        $this->assertSame(1, IntegrationSync::count());

        // Not configured: nothing is sent, the row waits.
        $this->artisan('erzap:sync')->assertSuccessful();
        $this->assertSame('waiting_config', $sync->fresh()->status);
        Http::assertNothingSent();

        // Configured but not mapped: waits for mapping, still nothing sent.
        config(['services.erzap.enabled' => true, 'services.erzap.base_url' => 'https://erzap.test/api', 'services.erzap.token' => 'erzap-secret']);
        $this->artisan('erzap:sync')->assertSuccessful();
        $this->assertSame('needs_mapping', $sync->fresh()->status);
        $this->assertStringContainsString('outlet Test Outlet', $sync->fresh()->last_error);
        Http::assertNothingSent();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs(User::factory()->create())->get('/admin/integrations')->assertForbidden();
        $this->actingAs($admin)->get('/admin/integrations/mapping')->assertInertia(fn (Assert $page) => $page->component('Admin/Integrations/Mapping')->has('products.data', 1));
        $this->put('/admin/integrations/mapping', ['outlets' => [['id' => $this->outlet->id, 'erzap_outlet_id' => 'OUT-9']], 'products' => [['id' => $this->product->id, 'erzap_product_id' => 'P-77', 'erzap_variant_id' => '', 'barcode' => '8990001']]])->assertSessionHasNoErrors();
        $this->assertSame('pending', $sync->fresh()->status);

        $this->artisan('erzap:sync')->assertSuccessful();
        $sync->refresh();
        $this->assertSame(['failed', 1], [$sync->status, $sync->attempts]);
        $this->assertStringContainsString('HTTP 500', $sync->last_error);
        $this->assertTrue($sync->next_attempt_at->equalTo(now()->addMinutes(5)));
        $this->post('/admin/integrations/syncs/'.$sync->id.'/retry')->assertSessionHasNoErrors();
        $sync->refresh();
        $this->assertSame(['synced', 'ERZ-123', 2], [$sync->status, $sync->external_ref, $sync->attempts]);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://erzap.test/api/transactions' && $request->hasHeader('Authorization', 'Bearer erzap-secret')
            && $request['reference'] === $order->order_code && $request['outlet_id'] === 'OUT-9' && $request['items'][0]['product_id'] === 'P-77' && $request['total'] === 175000
            && $request['payment']['method'] === 'qris' && ! isset($request['customer']['address']));
        $this->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('erzapSync.status', 'synced'));
        $this->get('/admin/integrations?status=synced')->assertInertia(fn (Assert $page) => $page->has('syncs.data', 1)->where('syncs.data.0.order_code', $order->order_code)->where('configured', true));

        // Cancelling the paid order voids the transaction in Erzap.
        $this->post('/admin/orders/'.$order->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Customer request'])->assertSessionHasNoErrors();
        $cancel = IntegrationSync::where('type', 'transaction.cancel')->where('subject_id', $order->id)->sole();
        $this->artisan('erzap:sync')->assertSuccessful();
        $this->assertSame('synced', $cancel->fresh()->status);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://erzap.test/api/transactions/cancel');
    }

    public function test_erzap_can_push_reference_stock_and_sync_results_with_its_token(): void
    {
        $this->product->update(['erzap_product_id' => 'P-77']);
        $this->postJson('/api/v1/erzap/stock', ['items' => [['erzap_product_id' => 'P-77', 'stock' => 12]]])->assertStatus(503);
        config(['services.erzap.webhook_token' => 'hook-secret']);
        $this->postJson('/api/v1/erzap/stock', ['items' => [['erzap_product_id' => 'P-77', 'stock' => 12]]], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/erzap/stock', ['items' => [['erzap_product_id' => 'P-77', 'stock' => 12], ['barcode' => 'nope', 'stock' => 1]]], ['Authorization' => 'Bearer hook-secret'])
            ->assertOk()->assertJson(['updated' => 1, 'unmatched' => ['nope']]);
        $this->assertSame(12, $this->product->fresh()->reference_stock);
        // Reference stock never blocks ordering, even at zero.
        $this->product->update(['reference_stock' => 0]);
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->has('products', 1));

        $order = $this->paidOrder();
        $this->postJson('/api/v1/erzap/sync-status', ['reference' => $order->order_code, 'status' => 'success', 'erzap_id' => 'ERZ-9'], ['Authorization' => 'Bearer hook-secret'])->assertOk()->assertJson(['status' => 'synced']);
        $this->assertSame('ERZ-9', IntegrationSync::sole()->external_ref);
        $this->postJson('/api/v1/erzap/sync-status', ['reference' => 'ORL-NOPE', 'status' => 'success'], ['Authorization' => 'Bearer hook-secret'])->assertNotFound();
    }

    public function test_rest_api_serves_public_catalog_and_token_protected_orders(): void
    {
        $this->outlet->update(['is_delivery_hub' => true]);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.name', 'Berry')->assertJsonPath('data.0.variant', 'Fullsize')->assertJsonMissingPath('data.0.sku');
        $this->getJson('/api/v1/outlets')->assertOk()->assertJsonPath('data.0.code', 'O');
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/schedule')->assertOk()->assertJsonPath('data.earliest_date', '2030-01-02')->assertJsonPath('data.cutoff_label', 'H-1 pukul 18.00 WITA');
        $this->getJson('/api/v1/products?outlet_id=999')->assertNotFound();

        $order = $this->paidOrder();
        $this->getJson('/api/v1/orders')->assertStatus(503);
        config(['services.api.tokens' => ['token-one', 'token-two']]);
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $response = $this->getJson('/api/v1/orders?payment_status=paid', ['Authorization' => 'Bearer token-two'])->assertOk()
            ->assertJsonPath('data.0.order_code', $order->order_code)->assertJsonPath('data.0.items.0.variant', 'Fullsize')->assertJsonPath('total', 1);
        $this->assertStringNotContainsString('Secret address', $response->getContent());
        $this->assertStringNotContainsString('sinta@example.test', $response->getContent());
        $this->assertStringNotContainsString('6281234567890', $response->getContent());
        $this->getJson('/api/v1/orders/'.$order->order_code, ['Authorization' => 'Bearer token-one'])->assertOk()->assertJsonPath('data.payments.0.status', 'paid');
        $this->getJson('/api/v1/reports/sales?from=2030-01-01&to=2030-01-31', ['Authorization' => 'Bearer token-one'])->assertOk()->assertJsonPath('data.summary.revenue', 175000);
    }
}
