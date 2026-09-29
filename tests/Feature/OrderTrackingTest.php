<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    private function order(string $code, array $overrides = []): Order
    {
        $category = Category::firstOrCreate(['name' => 'Brownies']);
        $product = Product::create(['category_id' => $category->id, 'sku' => 'SKU-'.Str::random(6), 'name' => 'Berry', 'variant' => 'Fullsize', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::firstOrCreate(['code' => 'TEST'], ['name' => 'Test Outlet', 'address' => 'Address', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Sinta Private', 'whatsapp' => '6281299990000', 'email' => 'sinta@example.test']);
        $order = Order::create([
            'order_code' => $code, 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64),
            'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => 'Test Outlet', 'fulfillment_method' => 'delivery',
            'requested_date' => '2030-01-03', 'delivery_address' => 'Jl. Rahasia 12', 'customer_note' => 'Private note', 'subtotal' => 160000, 'delivery_fee' => 15000,
            'total' => 175000, 'order_status' => 'confirmed', 'payment_status' => 'pending', ...$overrides,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => 'Berry', 'category_snapshot' => 'Brownies', 'variant_snapshot' => 'Fullsize',
            'sku_snapshot' => $product->sku, 'unit_price_snapshot' => 80000, 'quantity' => 2, 'subtotal' => 160000]);

        return $order;
    }

    public function test_order_code_shows_only_that_order_without_personal_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
        $order = $this->order('ORL-300101-TRACK00001');
        $this->order('ORL-300101-OTHER00001');
        Payment::create(['order_id' => $order->id, 'attempt' => 1, 'provider_order_id' => $order->order_code.'-P1', 'amount' => 175000, 'status' => 'pending',
            'payment_url' => 'https://sandbox.doku.com/checkout-link-v2/x', 'expires_at' => now()->addHours(6)]);

        $this->get('/cek-pesanan')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page->component('Shop/TrackOrder'));
        // The status URL alone shows nothing until the code is entered in this browser.
        $this->get('/cek-pesanan/ORL-300101-TRACK00001')->assertRedirect('/cek-pesanan?code=ORL-300101-TRACK00001');

        $this->post('/cek-pesanan', ['order_code' => ' orl-300101-track00001 '])->assertRedirect('/cek-pesanan/ORL-300101-TRACK00001');
        $this->get('/cek-pesanan/ORL-300101-TRACK00001')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Shop/OrderStatus')
                ->where('order.order_code', 'ORL-300101-TRACK00001')->where('order.order_status', 'confirmed')->has('order.items', 1)
                ->where('payment.url', 'https://sandbox.doku.com/checkout-link-v2/x')
                ->missing('order.customer')->missing('order.delivery_address')->missing('order.customer_note')->missing('order.id'))
            ->assertDontSee('Sinta Private')->assertDontSee('6281299990000')->assertDontSee('Jl. Rahasia 12')->assertDontSee('ORL-300101-OTHER00001');
        // Entering one code never opens another order, nor the private confirmation page.
        $this->get('/cek-pesanan/ORL-300101-OTHER00001')->assertRedirect('/cek-pesanan?code=ORL-300101-OTHER00001');
        $this->get('/orders/ORL-300101-TRACK00001')->assertNotFound();

        // An expired link is not offered.
        $this->travelTo(CarbonImmutable::parse('2030-01-02 12:00:00', 'Asia/Makassar'));
        $this->get('/cek-pesanan/ORL-300101-TRACK00001')->assertInertia(fn (Assert $page) => $page->where('payment', null));
    }

    public function test_unknown_codes_are_rejected_and_guessing_is_limited(): void
    {
        $this->post('/cek-pesanan', ['order_code' => ''])->assertSessionHasErrors('order_code');
        for ($i = 0; $i < 10; $i++) {
            $this->post('/cek-pesanan', ['order_code' => 'ORL-000000-NOPE'.$i])->assertSessionHasErrors(['order_code' => 'Order Code tidak ditemukan. Periksa kembali huruf dan angkanya.']);
        }
        $this->order('ORL-300101-TRACK00002');
        $this->post('/cek-pesanan', ['order_code' => 'ORL-300101-TRACK00002'])->assertSessionHasErrors('order_code');
        $this->assertStringStartsWith('Terlalu banyak percobaan', session('errors')->first('order_code'));
    }
}
