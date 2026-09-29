<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\PublicPage;
use Tests\TestCase;

class PreorderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function catalog(): array
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
        $category = Category::create(['name' => 'FULLSIZE TEST']);
        $product = Product::create(['category_id' => $category->id, 'sku' => 'TEST-PO', 'name' => 'Test Brownie', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::create(['code' => 'TEST-PO', 'name' => 'Test Outlet', 'address' => 'Outlet address', 'is_active' => true, 'is_delivery_hub' => true]);

        return [$product, $outlet];
    }

    private function checkout(Product $product, Outlet $outlet): array
    {
        $this->get('/order?outlet_id='.$outlet->id)->assertOk();

        return [
            'checkout_key' => session('checkout_key'), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'quoted_price' => $product->priceAt($outlet)]],
            'name' => 'Customer Test', 'whatsapp' => '081234567890', 'email' => null, 'outlet_id' => $outlet->id,
            'fulfillment_method' => 'pickup', 'requested_date' => '2030-01-02', 'requested_time' => '14:30',
            'customer_note' => 'Test note',
        ];
    }

    public function test_single_form_lists_active_products_without_a_cart_and_validates_selection(): void
    {
        [$product, $outlet] = $this->catalog();
        $this->get('/order')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shop/OrderForm')->has('products', 1)->has('outlets', 1));
        foreach (['/products', '/products/'.$product->id, '/cart', '/checkout'] as $legacy) {
            $this->get($legacy)->assertRedirect('/order');
        }
        $payload = $this->checkout($product, $outlet);
        foreach ([0, -1, 100] as $quantity) {
            $payload['items'][0]['quantity'] = $quantity;
            $this->post('/order', $payload)->assertSessionHasErrors('items.0.quantity');
        }
        $payload['items'][0]['quantity'] = 1;
        $payload['items'][] = $payload['items'][0];
        $this->post('/order', $payload)->assertSessionHasErrors('items.0.product_id');
        $this->post('/order', [...$payload, 'items' => []])->assertSessionHasErrors('items');
        $product->update(['is_active' => false]);
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->has('products', 0));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_single_form_saves_multiple_products_with_distinct_outlet_prices(): void
    {
        [$product, $outlet] = $this->catalog();
        $second = Product::create(['category_id' => $product->category_id, 'sku' => 'SECOND-PO', 'name' => 'Second Brownie', 'price' => 45000, 'is_active' => true]);
        $second->outletPrices()->create(['outlet_id' => $outlet->id, 'price' => 50000]);
        $payload = $this->checkout($product, $outlet);
        $payload['items'][] = ['product_id' => $second->id, 'quantity' => 3, 'quoted_price' => 50000];
        $this->get('/order?outlet_id='.$outlet->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedOutlet', $outlet->id)
            ->where('products', fn ($products) => collect($products)->firstWhere('id', $second->id)['price'] === 50000));
        $this->post('/order', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::with('items')->firstOrFail();
        $this->assertSame(310000, $order->total);
        $this->assertCount(2, $order->items);
        $this->assertSame(150000, $order->items->firstWhere('product_id', $second->id)->subtotal);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_order_uses_outlet_price_snapshots_and_cannot_accept_client_totals_or_paid_status(): void
    {
        [$product, $outlet] = $this->catalog();
        $product->outletPrices()->create(['outlet_id' => $outlet->id, 'price' => 85000]);
        $payload = $this->checkout($product, $outlet);
        $this->post('/order', [...$payload, 'total' => 1, 'price' => 1, 'payment_status' => 'paid', 'order_status' => 'completed'])->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::with('customer', 'items')->firstOrFail();
        $this->assertSame(170000, $order->total);
        $this->assertSame(0, $order->delivery_fee);
        $this->assertSame('pending_review', $order->order_status);
        $this->assertSame('not_created', $order->payment_status);
        $this->assertSame('6281234567890', $order->customer->whatsapp);
        $this->assertSame(85000, $order->items[0]->unit_price_snapshot);
        $this->assertNull(session('checkout_key'));
        $product->update(['name' => 'Changed later', 'price' => 90000]);
        $this->assertSame('Test Brownie', $order->fresh('items')->items[0]->product_name_snapshot);
        $this->assertDatabaseCount('order_status_histories', 1);
        $audit = DB::table('audit_logs')->where('action', 'order.created')->value('changes');
        $this->assertStringNotContainsString('6281234567890', $audit);
    }

    public function test_repeated_submission_is_idempotent_and_other_sessions_cannot_read_confirmation(): void
    {
        [$product, $outlet] = $this->catalog();
        $payload = $this->checkout($product, $outlet);
        $this->post('/order', $payload)->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->post('/order', $payload)->assertRedirect('/orders/'.$order->order_code);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->get('/orders/'.$order->order_code)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->missing('order.owner_hash')->missing('order.checkout_key')->where('order.order_code', $order->order_code)->where('whatsappUrl', fn ($url) => str_contains($url, rawurlencode($order->order_code))));
        $this->withSession(['order_owner' => 'another-visitor'])->get('/orders/'.$order->order_code)->assertNotFound();
        $this->post('/order', $payload)->assertNotFound();
    }

    public function test_price_changes_require_review_and_new_quote_before_order_creation(): void
    {
        [$product, $outlet] = $this->catalog();
        $payload = $this->checkout($product, $outlet);
        $product->update(['price' => 90000]);
        $this->post('/order', $payload)->assertSessionHasErrors('pricing');
        $this->assertDatabaseCount('orders', 0);
        $this->get('/order?outlet_id='.$outlet->id)->assertOk();
        $payload['items'][0]['quoted_price'] = 90000;
        $this->post('/order', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['total' => 180000]);
    }

    public function test_same_day_and_cutoff_crossing_are_rejected_at_submit(): void
    {
        [$product, $outlet] = $this->catalog();
        $payload = $this->checkout($product, $outlet);
        $this->post('/order', [...$payload, 'requested_date' => '2030-01-01'])->assertSessionHasErrors('requested_date');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-01-01 18:00:01', 'Asia/Makassar'));
        $this->post('/order', $payload)->assertSessionHasErrors('requested_date');
        $this->post('/order', [...$payload, 'requested_date' => '2030-01-03'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_delivery_requires_address_and_keeps_unknown_fee_distinct_from_free_delivery(): void
    {
        [$product, $outlet] = $this->catalog();
        $payload = [...$this->checkout($product, $outlet), 'fulfillment_method' => 'delivery', 'email' => 'CUSTOMER@example.test'];
        $this->post('/order', $payload)->assertSessionHasErrors('delivery_address');
        $this->post('/order', [...$payload, 'delivery_address' => 'Private destination'])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertNull($order->delivery_fee);
        $this->assertSame('customer@example.test', $order->customer->email);
        $this->get('/orders/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('whatsappUrl', fn ($url) => ! str_contains(urldecode($url), 'Private destination') && ! str_contains($url, 'example.test')));
    }

    public function test_inactive_outlets_products_and_tampered_quotes_are_rejected_without_partial_order(): void
    {
        [$product, $outlet] = $this->catalog();
        $payload = $this->checkout($product, $outlet);
        $this->post('/order', array_replace($payload, ['items' => [['product_id' => $product->id, 'quantity' => 2, 'quoted_price' => 1]]]))->assertSessionHasErrors('pricing');
        $outlet->update(['is_active' => false]);
        $this->post('/order', $payload)->assertSessionHasErrors('outlet_id');
        $outlet->update(['is_active' => true]);
        $product->update(['is_active' => false]);
        $this->post('/order', $payload)->assertSessionHasErrors('items.0.product_id');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_staff_can_find_and_read_orders_while_guests_cannot(): void
    {
        [$product, $outlet] = $this->catalog();
        $this->post('/order', $this->checkout($product, $outlet))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->get('/admin/orders/'.$order->id)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/orders?search='.$order->order_code)->assertOk()->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Orders/Show')->missing('order.owner_hash')->has('order.items', 1));
        $this->get('/admin/orders?date=2030-01-03')->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    }

    public function test_staff_review_updates_shipping_and_keeps_internal_notes_private(): void
    {
        [$product, $outlet] = $this->catalog();
        $this->post('/order', [...$this->checkout($product, $outlet), 'fulfillment_method' => 'delivery', 'delivery_address' => 'Test destination'])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $url = '/admin/orders/'.$order->id.'/review';
        $payload = ['review_version' => 0, 'delivery_fee' => 15000, 'note' => 'Internal capacity check'];
        $this->post($url, $payload)->assertRedirect('/admin/login');
        $staff = User::factory()->create();
        $this->actingAs($staff)->post($url, [...$payload, 'payment_status' => 'paid', 'total' => 1])->assertSessionHasNoErrors();
        $this->assertSame(175000, $order->fresh()->total);
        $this->assertSame('not_created', $order->fresh()->payment_status);
        $this->assertSame('pending_review', $order->fresh()->order_status);
        $this->assertDatabaseHas('order_reviews', ['order_id' => $order->id, 'actor_id' => $staff->id, 'previous_delivery_fee' => null, 'delivery_fee' => 15000]);
        $this->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->has('reviews.data', 1)->where('reviews.data.0.note', $payload['note']));
        $this->get('/orders/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->missing('reviews')->missing('order.reviews')->where('order.total', 175000))->assertDontSee($payload['note']);
        $this->assertStringNotContainsString($payload['note'], DB::table('audit_logs')->where('action', 'order.reviewed')->value('changes'));
        $this->post($url, $payload)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('order_reviews', 1);
        $this->post($url, [...$payload, 'review_version' => 1, 'delivery_fee' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, $order->fresh()->delivery_fee);
        $this->post($url, [...$payload, 'review_version' => 2, 'delivery_fee' => null])->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->delivery_fee);
        $this->assertSame(160000, $order->fresh()->total);
    }

    public function test_review_rejects_invalid_fees_and_locks_orders_after_review_stage(): void
    {
        [$product, $outlet] = $this->catalog();
        $this->post('/order', $this->checkout($product, $outlet))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $url = '/admin/orders/'.$order->id.'/review';
        $payload = ['review_version' => 0, 'delivery_fee' => 0, 'note' => 'Checked pickup'];
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([-1, 1.5, 1000000000, 1000, null] as $fee) {
            $this->post($url, [...$payload, 'delivery_fee' => $fee])->assertSessionHasErrors('delivery_fee');
        }
        $this->post($url, [...$payload, 'note' => ''])->assertSessionHasErrors('note');
        $this->assertDatabaseCount('order_reviews', 0);
        $order->update(['payment_status' => 'paid']);
        $this->post($url, $payload)->assertSessionHasErrors('review');
        $order->update(['payment_status' => 'not_created', 'order_status' => 'cancelled']);
        $this->post($url, $payload)->assertSessionHasErrors('review');
        $this->assertDatabaseCount('order_reviews', 0);
        $this->assertSame(160000, $order->fresh()->total);
    }

    public function test_disabled_staff_cannot_review_orders(): void
    {
        [$product, $outlet] = $this->catalog();
        $this->post('/order', $this->checkout($product, $outlet))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->actingAs(User::factory()->create(['is_active' => false]))
            ->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 0, 'note' => 'Denied'])
            ->assertRedirect('/admin/login');
        $this->assertDatabaseCount('order_reviews', 0);
    }

    public function test_checkout_is_rate_limited_and_sensitive_pages_never_load_tracking(): void
    {
        [$product, $outlet] = $this->catalog();
        config(['site.meta_pixel_enabled' => true]);
        $payload = $this->checkout($product, $outlet);
        $this->get('/order?outlet_id='.$outlet->id)->assertDontSee('/assets/js/meta-pixel.js', false)->assertSee('noindex,nofollow', false);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/order', [...$payload, 'name' => ''])->assertSessionHasErrors('name');
        }
        $this->post('/order', $payload)->assertStatus(429);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_form_lists_categories_and_variants_for_the_chosen_outlet(): void
    {
        [$product, $outlet] = $this->catalog();
        $brownies = Category::create(['name' => 'Brownies', 'image' => '/assets/images/outlet1.jpg']);
        $full = Product::create(['category_id' => $brownies->id, 'variant' => 'Fullsize', 'sku' => 'B-F', 'name' => 'Berry', 'price' => 115000, 'is_active' => true]);
        Product::create(['category_id' => $brownies->id, 'variant' => 'Halfsize', 'sku' => 'B-H', 'name' => 'Berry', 'price' => 75000, 'is_active' => true]);
        Product::create(['category_id' => $brownies->id, 'variant' => 'Halfsize', 'sku' => 'B-X', 'name' => 'Hidden', 'price' => 1, 'is_active' => false]);
        DB::table('outlet_prices')->insert(['product_id' => $full->id, 'outlet_id' => $outlet->id, 'price' => 120000, 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/order')->assertInertia(fn (Assert $page) => $page->has('categories', 2)->has('products', 3)->where('selectedOutlet', null)
            ->where('deliveryOutlet.id', $outlet->id)
            ->where('products', fn ($products) => collect($products)->every(fn ($product) => ! isset($product['sku']))
                && collect($products)->where('name', 'Berry')->pluck('variant')->sort()->values()->all() === ['Fullsize', 'Halfsize']
                && collect($products)->firstWhere('id', $full->id)['price'] === 115000));
        // Choosing the outlet reprices the cart with that outlet's overrides.
        $this->get('/order?outlet_id='.$outlet->id)->assertInertia(fn (Assert $page) => $page->where('selectedOutlet', $outlet->id)
            ->where('products', fn ($products) => collect($products)->firstWhere('id', $full->id)['price'] === 120000));
    }

    public function test_delivery_ships_from_the_delivery_outlet_and_time_is_required(): void
    {
        [$product, $outlet] = $this->catalog();
        $other = Outlet::create(['code' => 'OTHER', 'name' => 'Other Outlet', 'address' => 'Other', 'is_active' => true]);
        $payload = $this->checkout($product, $outlet);
        $this->post('/order', [...$payload, 'requested_time' => ''])->assertSessionHasErrors('requested_time');
        $this->post('/order', [...$payload, 'fulfillment_method' => 'pickup', 'outlet_id' => null])->assertSessionHasErrors('outlet_id');

        // Any outlet sent with a delivery order is ignored; the delivery outlet fulfils it.
        $this->post('/order', [...$payload, 'fulfillment_method' => 'delivery', 'outlet_id' => $other->id, 'delivery_address' => 'Jl. Delivery'])->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame([$outlet->id, 'Test Outlet', null], [$order->outlet_id, $order->outlet_name_snapshot, $order->delivery_fee]);

        $outlet->update(['is_delivery_hub' => false]);
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->where('deliveryOutlet', null));
        $this->post('/order', [...$this->checkout($product, $outlet), 'fulfillment_method' => 'delivery', 'delivery_address' => 'Jl. Delivery'])->assertSessionHasErrors('fulfillment_method');
    }

    public function test_whatsapp_message_is_readable_and_uses_the_number_from_website_content(): void
    {
        [$product, $outlet] = $this->catalog();
        $product->update(['variant' => 'Halfsize']);
        $this->post('/order', [...$this->checkout($product, $outlet), 'customer_note' => 'Tulis: Happy Birthday'])->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame('Halfsize', $order->items->sole()->variant_snapshot);

        $url = null;
        $this->get('/orders/'.$order->order_code)->assertInertia(function (Assert $page) use (&$url) {
            $url = $page->toArray()['props']['whatsappUrl'];
        });
        $this->assertStringStartsWith('https://wa.me/6282145809558?text=', $url);
        $message = rawurldecode(explode('?text=', $url)[1]);
        // Emoji and typographic symbols do not survive every WhatsApp client, so the message stays plain ASCII.
        $this->assertDoesNotMatchRegularExpression('/[^\x{0}-\x{7F}]/u', $message);
        foreach (['*Order Code:* '.$order->order_code, '- 2x Test Brownie (Halfsize) = Rp 160.000', '*Subtotal:* Rp 160.000', '*Penerimaan:* Pickup di Test Outlet',
            '*Jadwal:* Rabu, 2 Januari 2030, 14:30 WITA', '*Catatan:* Tulis: Happy Birthday'] as $line) {
            $this->assertStringContainsString($line, $message);
        }
        $this->assertStringNotContainsString('Outlet address', $message);

        $this->actingAs(User::factory()->create(['role' => 'content_editor']));
        $this->post('/admin/content/social', ['value' => ['instagramUrl' => 'http://insecure.test', 'tiktokUrl' => '', 'whatsappNumber' => '0812']])->assertSessionHasErrors(['value.instagramUrl', 'value.whatsappNumber']);
        $this->post('/admin/content/social', ['value' => ['instagramUrl' => 'https://instagram.com/orlena', 'tiktokUrl' => '', 'whatsappNumber' => '6281111111111']])->assertSessionHasNoErrors();
        $this->get('/')->assertPublicPage(fn (PublicPage $page) => $page->where('site', ['instagramUrl' => 'https://instagram.com/orlena', 'tiktokUrl' => '', 'whatsappNumber' => '6281111111111']));
        $this->get('/orders/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('whatsappUrl', fn ($link) => str_starts_with($link, 'https://wa.me/6281111111111?text=')));
    }

    public function test_greeting_card_is_optional_and_kept_only_for_hampers(): void
    {
        [$product, $outlet] = $this->catalog();
        $payload = $this->checkout($product, $outlet);
        // A regular order never stores a card message.
        $this->post('/order', [...$payload, 'card_message' => 'Selamat!'])->assertSessionHasNoErrors();
        $this->assertNull(Order::sole()->card_message);

        $hamper = Product::create(['category_id' => $product->category_id, 'sku' => 'HMP-1', 'name' => 'Hampers Lebaran', 'price' => 350000, 'is_active' => true,
            'is_hamper' => true, 'hamper_contents' => "Brownies\nKartu"]);
        $payload = $this->checkout($product, $outlet);
        $payload['items'][] = ['product_id' => $hamper->id, 'quantity' => 1, 'quoted_price' => 350000];
        $this->post('/order', [...$payload, 'card_message' => str_repeat('a', 301)])->assertSessionHasErrors('card_message');
        $this->post('/order', [...$payload, 'card_message' => '  Selamat Hari Raya, dari Sinta.  '])->assertSessionHasNoErrors();
        $order = Order::latest('id')->first();
        $this->assertSame('Selamat Hari Raya, dari Sinta.', $order->card_message);
        $this->get('/orders/'.$order->order_code)->assertInertia(fn (Assert $page) => $page
            ->where('order.card_message', 'Selamat Hari Raya, dari Sinta.')
            ->where('whatsappUrl', fn ($link) => str_contains(rawurldecode($link), '*Kartu ucapan:* Selamat Hari Raya, dari Sinta.')));

        // Without a message the hampers order is fine too.
        $payload = $this->checkout($product, $outlet);
        $payload['items'][] = ['product_id' => $hamper->id, 'quantity' => 1, 'quoted_price' => 350000];
        $this->post('/order', $payload)->assertSessionHasNoErrors();
        $this->assertNull(Order::latest('id')->first()->card_message);
    }
}
