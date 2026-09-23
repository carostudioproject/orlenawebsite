<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderAdditionTest extends TestCase
{
    use RefreshDatabase;

    private Product $brownie;

    private Product $sauce;

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
        $category = Category::create(['name' => 'Brownies']);
        $this->brownie = Product::create(['category_id' => $category->id, 'variant' => 'Fullsize', 'sku' => 'B-F', 'name' => 'Berry', 'price' => 80000, 'is_active' => true]);
        $this->sauce = Product::create(['category_id' => $category->id, 'sku' => 'S-1', 'name' => 'Nutella', 'price' => 18000, 'is_active' => true]);
        $this->outlet = Outlet::create(['code' => 'O', 'name' => 'Test Outlet', 'address' => 'Jl', 'is_active' => true]);
    }

    /** Places a pickup order for 2x Berry in this browser session and returns it. */
    private function placeOrder(): Order
    {
        $this->get('/order?outlet_id='.$this->outlet->id);
        $this->post('/order', [
            'checkout_key' => session('checkout_key'), 'items' => [['product_id' => $this->brownie->id, 'quantity' => 2, 'quoted_price' => 80000]],
            'name' => 'Customer', 'whatsapp' => '081234567890', 'outlet_id' => $this->outlet->id, 'fulfillment_method' => 'pickup',
            'requested_date' => '2030-01-03', 'requested_time' => '10:00',
        ])->assertSessionHasNoErrors();

        return Order::sole();
    }

    private function addItems(Order $order, array $items): TestResponse
    {
        $this->get('/orders/'.$order->order_code.'/tambah')->assertOk();

        return $this->post('/orders/'.$order->order_code.'/tambah', ['add_key' => session('add_key'), 'items' => $items]);
    }

    public function test_the_ordering_browser_adds_items_that_merge_and_update_the_total(): void
    {
        $order = $this->placeOrder();
        $this->get('/orders/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('canAddItems', true)->where('latestAddition', null));
        $this->get('/orders/'.$order->order_code.'/tambah')->assertInertia(fn (Assert $page) => $page->component('Shop/AddItems')->where('blocker', null)
            ->where('order.total', 160000)->where('order.requested_date', '2030-01-03')->has('order.items', 1)->has('products', 2));

        $key = session('add_key');
        $items = [['product_id' => $this->brownie->id, 'quantity' => 1, 'quoted_price' => 80000], ['product_id' => $this->sauce->id, 'quantity' => 3, 'quoted_price' => 18000]];
        $this->post('/orders/'.$order->order_code.'/tambah', ['add_key' => $key, 'items' => $items])->assertRedirect('/orders/'.$order->order_code);
        // The same form sent twice never doubles the addition.
        $this->post('/orders/'.$order->order_code.'/tambah', ['add_key' => $key, 'items' => $items])->assertRedirect('/orders/'.$order->order_code);

        $order->refresh();
        // 2x Berry (160.000) + 1x Berry (80.000) + 3x Nutella (54.000).
        $this->assertSame([294000, 294000, 1], [$order->subtotal, $order->total, $order->review_version]);
        $this->assertSame([3, 3], $order->items()->orderBy('id')->pluck('quantity')->all());
        $this->assertDatabaseCount('order_additions', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.items_added', 'subject_id' => $order->id]);

        $this->get('/orders/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('latestAddition.new_total', 294000)
            ->where('latestAddition.whatsappUrl', fn ($url) => str_contains(rawurldecode($url), '- 3x Nutella = Rp 54.000') && str_contains(rawurldecode($url), '*Total awal baru:* Rp 294.000')));
        $this->actingAs(User::factory()->create())->get('/admin/orders/'.$order->id)
            ->assertInertia(fn (Assert $page) => $page->has('additions', 1)->where('additions.0.subtotal_added', 134000)->where('order.total', 294000));
    }

    public function test_another_device_needs_the_order_code_and_the_whatsapp_used_for_the_order(): void
    {
        $order = $this->placeOrder();
        $this->flushSession();

        $this->get('/orders/'.$order->order_code.'/tambah')->assertRedirect('/order/tambah?code='.$order->order_code);
        $this->get('/orders/'.$order->order_code)->assertNotFound();
        $this->get('/order/tambah?code='.strtolower($order->order_code))->assertInertia(fn (Assert $page) => $page->component('Shop/FindOrder')->where('code', $order->order_code));
        $this->post('/order/tambah', ['order_code' => $order->order_code, 'whatsapp' => '089999999999'])->assertSessionHasErrors(['order_code' => 'Order Code atau nomor WhatsApp tidak cocok.']);
        $this->post('/order/tambah', ['order_code' => 'ORL-000000-NOPE', 'whatsapp' => '081234567890'])->assertSessionHasErrors(['order_code' => 'Order Code atau nomor WhatsApp tidak cocok.']);

        // Lowercase code and +62 formatting are accepted.
        $this->post('/order/tambah', ['order_code' => strtolower($order->order_code), 'whatsapp' => '+62 812-3456-7890'])->assertRedirect('/orders/'.$order->order_code.'/tambah');
        $this->get('/orders/'.$order->order_code)->assertOk();
        $this->addItems($order, [['product_id' => $this->sauce->id, 'quantity' => 1, 'quoted_price' => 18000]])->assertSessionHasNoErrors();
        $this->assertSame(178000, $order->fresh()->total);
    }

    public function test_wrong_guesses_are_rate_limited_per_order_code(): void
    {
        $order = $this->placeOrder();
        $this->flushSession();
        // Only the lookup limit is under test here, not the general per-minute form throttle.
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (range(1, 5) as $attempt) {
            $this->post('/order/tambah', ['order_code' => $order->order_code, 'whatsapp' => '08100000000'.$attempt])->assertSessionHasErrors('order_code');
        }
        // Even the correct number is refused until the lock expires, so guessing stays slow.
        $this->post('/order/tambah', ['order_code' => $order->order_code, 'whatsapp' => '081234567890'])->assertSessionHasErrors('order_code');
        $this->assertStringStartsWith('Terlalu banyak percobaan.', session('errors')->first('order_code'));
        $this->get('/orders/'.$order->order_code.'/tambah')->assertRedirect();
    }

    public function test_confirmed_orders_and_passed_cutoffs_cannot_be_extended_by_customers(): void
    {
        $order = $this->placeOrder();
        $item = [['product_id' => $this->sauce->id, 'quantity' => 1, 'quoted_price' => 18000]];

        $order->update(['order_status' => 'confirmed']);
        $this->get('/orders/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('canAddItems', false));
        $this->get('/orders/'.$order->order_code.'/tambah')->assertInertia(fn (Assert $page) => $page->where('blocker', fn ($text) => str_contains($text, 'sudah dikonfirmasi')));
        $this->addItems($order, $item)->assertSessionHasErrors('order');

        $order->update(['order_status' => 'pending_review']);
        $this->travelTo(CarbonImmutable::parse('2030-01-02 18:01:00', 'Asia/Makassar'));
        $this->addItems($order, $item)->assertSessionHasErrors('order');
        $this->assertStringContainsString('H-1 pukul 18.00', session('errors')->first('order'));
        $this->assertSame(160000, $order->fresh()->total);
        $this->assertDatabaseCount('order_additions', 0);
    }

    public function test_prices_come_from_the_database_and_line_limits_hold(): void
    {
        $order = $this->placeOrder();
        $this->addItems($order, [['product_id' => $this->brownie->id, 'quantity' => 1, 'quoted_price' => 1]])->assertSessionHasErrors('pricing');
        $this->addItems($order, [['product_id' => $this->brownie->id, 'quantity' => 98, 'quoted_price' => 80000]])->assertSessionHasErrors('items.0.quantity');
        $this->addItems($order, [])->assertSessionHasErrors('items');

        // The outlet's own price applies to additions, as it did for the original items.
        DB::table('outlet_prices')->insert(['product_id' => $this->sauce->id, 'outlet_id' => $this->outlet->id, 'price' => 20000, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/orders/'.$order->order_code.'/tambah')->assertInertia(fn (Assert $page) => $page->where('products', fn ($products) => collect($products)->firstWhere('id', $this->sauce->id)['price'] === 20000));
        $this->addItems($order, [['product_id' => $this->sauce->id, 'quantity' => 1, 'quoted_price' => 20000]])->assertSessionHasNoErrors();
        $this->assertSame(180000, $order->fresh()->total);
    }
}
