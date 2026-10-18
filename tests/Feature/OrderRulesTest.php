<?php

namespace Tests\Feature;

use App\Actions\Payments\ApplyPaymentStatus;
use App\Http\Requests\CheckoutRequest;
use App\Models\Category;
use App\Models\ClosedDate;
use App\Models\Customer;
use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ordering\Capacity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Rules Order Website (Revisi 2): cutoff H-1 19.00, payment close H-1 21.00, 2-hour links, at most 30 days ahead. */
class OrderRulesTest extends TestCase
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
        config(['services.doku.enabled' => false, 'services.erzap.enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
    }

    private function order(array $overrides = []): Order
    {
        $category = Category::firstOrCreate(['name' => 'Brownies']);
        $product = Product::create(['category_id' => $category->id, 'sku' => 'SKU-'.Str::random(6), 'name' => 'Brownie', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::firstOrCreate(['code' => 'TEST'], ['name' => 'Test Outlet', 'address' => 'Address', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Sinta', 'whatsapp' => '6281234567890']);
        $order = Order::create([
            'order_code' => 'ORL-300101-'.strtoupper(Str::random(10)), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64),
            'request_hash' => str_repeat('b', 64), 'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name,
            'fulfillment_method' => 'pickup', 'requested_date' => '2030-01-02', 'requested_time' => '14:00', 'subtotal' => 80000, 'delivery_fee' => 0, 'total' => 80000,
            'order_status' => 'confirmed', 'payment_status' => 'pending', ...$overrides,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name_snapshot' => 'Brownie', 'category_snapshot' => 'Brownies', 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 80000, 'quantity' => 1, 'subtotal' => 80000,
        ]);

        return $order;
    }

    /** A payment row outside DOKU (no gateway to ask), like a link waiting to be paid. */
    private function payment(Order $order, string $status = 'pending', ?int $amount = null): Payment
    {
        $attempt = (int) Payment::where('order_id', $order->id)->max('attempt') + 1;

        return Payment::create([
            'order_id' => $order->id, 'attempt' => $attempt, 'provider' => 'manual', 'provider_order_id' => $order->order_code.'-P'.$attempt,
            'open_order_id' => $status === 'pending' ? $order->id : null, 'amount' => $amount ?? $order->total, 'status' => $status,
        ]);
    }

    private function pay(Payment $payment, ?string $paidAt = null): string
    {
        return app(ApplyPaymentStatus::class)->handle(['order_id' => $payment->provider_order_id, 'transaction_status' => 'SUCCESS', 'gross_amount' => (string) $payment->amount, 'payment_type' => 'TEST', 'transaction_date' => $paidAt], 'test');
    }

    public function test_the_providers_payment_time_decides_the_cutoff_and_extra_receipts_never_move_the_order(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        // Paid at 18:59 WITA (17:59 WIB), notified at 19:01: still produced for the chosen date.
        $this->travelTo(CarbonImmutable::parse('2030-01-01 19:01:00', 'Asia/Makassar'));
        $this->pay($payment, '2030-01-01T17:59:00+07:00');
        $this->assertSame(['paid', '2030-01-02', null], [$order->fresh()->payment_status, $order->fresh()->requested_date->toDateString(), $order->fresh()->moved_from_date]);
        $this->assertSame('2030-01-01 18:59', $payment->fresh()->paid_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i'));
        // An older link paid later only adds money: the settled order keeps its date.
        $older = $this->payment($order, 'cancelled');
        $this->pay($older, '2030-01-01T19:00:30+08:00');
        $this->assertSame(['2030-01-02', null], [$order->fresh()->requested_date->toDateString(), $order->fresh()->moved_from_date]);

        // A time without a zone is not trusted (WIB or WITA?): the receipt time counts.
        $other = $this->order();
        $this->pay($this->payment($other), '2030-01-01 18:30:00');
        $this->assertSame('2030-01-03', $other->fresh()->requested_date->toDateString());
    }

    public function test_only_real_google_maps_links_are_accepted(): void
    {
        foreach (['https://maps.app.goo.gl/AbC123', 'https://www.google.com/maps/place/x', 'https://goo.gl/maps/xyz', 'https://maps.google.co.id/?q=1'] as $url) {
            $this->assertTrue(CheckoutRequest::isMapsUrl($url), $url);
        }
        foreach (['https://google.attacker.com/maps', 'http://maps.app.goo.gl/x', 'https://www.google.com/search?q=maps', 'https://maps.app.goo.gl.evil.test/x', 'https://user@maps.app.goo.gl/x'] as $url) {
            $this->assertFalse(CheckoutRequest::isMapsUrl($url), $url);
        }
    }

    public function test_paid_before_the_cutoff_keeps_the_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01 18:59:00', 'Asia/Makassar'));
        $order = $this->order();
        $this->pay($this->payment($order));
        $this->assertSame(['paid', '2030-01-02', null], [$order->fresh()->payment_status, $order->fresh()->requested_date->toDateString(), $order->fresh()->moved_from_date]);
    }

    public function test_paid_after_the_cutoff_moves_to_the_next_open_date(): void
    {
        ClosedDate::create(['date' => '2030-01-03', 'reason' => 'Libur']);
        $order = $this->order();
        $payment = $this->payment($order);
        // 20.30 on D-1: production for 2 January is closed; 3 January is closed too, so 4 January.
        $this->travelTo(CarbonImmutable::parse('2030-01-01 20:30:00', 'Asia/Makassar'));
        $this->assertSame('applied', $this->pay($payment));

        $order->refresh();
        $this->assertSame(['paid', '2030-01-04', '2030-01-02', 1], [$order->payment_status, $order->requested_date->toDateString(), $order->moved_from_date->toDateString(), $order->review_version]);
        $this->assertDatabaseHas('order_reviews', ['order_id' => $order->id, 'actor_id' => null, 'previous_schedule' => '2030-01-02 14:00', 'schedule' => '2030-01-04 14:00']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.moved_after_cutoff']);

        $this->actingAs(User::factory()->create())->get('/admin/orders/'.$order->id)
            ->assertInertia(fn (Assert $page) => $page->where('order.moved_from_date', '2030-01-02')->where('order.requested_date', '2030-01-04')->where('poPaymentClose', 'D-1 at 21:00 WITA'));
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('order.moved_from_date', '2030-01-02')->where('paymentClose', 'H-1 pukul 21.00 WITA'));
    }

    public function test_unpaid_orders_are_cancelled_at_payment_close(): void
    {
        $waiting = $this->order(['order_status' => 'pending_review', 'payment_status' => 'not_created']);
        $linked = $this->order();
        $this->payment($linked);
        $paid = $this->order(['payment_status' => 'paid']);
        $this->payment($paid, 'paid');
        $partly = $this->order(['payment_status' => 'pending']);
        $this->payment($partly, 'paid', 50000);
        $later = $this->order(['requested_date' => '2030-01-03']);

        $this->travelTo(CarbonImmutable::parse('2030-01-01 20:59:00', 'Asia/Makassar'));
        $this->artisan('orders:cancel-unpaid')->expectsOutputToContain('Cancelled 0')->assertSuccessful();

        $this->travelTo(CarbonImmutable::parse('2030-01-01 21:00:00', 'Asia/Makassar'));
        $this->artisan('orders:cancel-unpaid')->expectsOutputToContain('Cancelled 2')->assertSuccessful();
        $this->assertSame(['cancelled', 'not_created'], [$waiting->fresh()->order_status, $waiting->fresh()->payment_status]);
        $this->assertSame(['cancelled', 'cancelled'], [$linked->fresh()->order_status, $linked->fresh()->payment_status]);
        $this->assertSame(['cancelled', null], [Payment::where('order_id', $linked->id)->sole()->status, Payment::where('order_id', $linked->id)->sole()->open_order_id]);
        $this->assertSame('confirmed', $paid->fresh()->order_status);
        // Partly paid: staff collect the rest or refund; never cancelled automatically.
        $this->assertSame('confirmed', $partly->fresh()->order_status);
        $this->assertSame('confirmed', $later->fresh()->order_status);
        $this->assertSame([null, 'Otomatis: belum dibayar sampai batas pembayaran (H-1 pukul 21.00 WITA).'],
            array_values((array) DB::table('order_status_histories')->where('order_id', $linked->id)->where('to_status', 'cancelled')->sole(['actor_id', 'note'])));

        // A payment arriving after the cancellation is still recorded, and the order is not moved.
        $this->travelTo(CarbonImmutable::parse('2030-01-01 21:05:00', 'Asia/Makassar'));
        $this->pay(Payment::where('order_id', $linked->id)->sole());
        $this->assertSame(['cancelled', 'paid', null], [$linked->fresh()->order_status, $linked->fresh()->payment_status, $linked->fresh()->moved_from_date]);
    }

    public function test_dates_go_up_to_thirty_days_ahead(): void
    {
        $order = $this->order(['order_status' => 'pending_review', 'payment_status' => 'not_created', 'requested_date' => '2030-01-03']);
        $this->actingAs(User::factory()->create());
        $review = ['review_version' => 0, 'delivery_fee' => 0, 'note' => 'Customer asked', 'requested_time' => '14:00'];
        $this->post('/admin/orders/'.$order->id.'/review', [...$review, 'requested_date' => '2030-02-01'])->assertSessionHasErrors('requested_date');
        $this->post('/admin/orders/'.$order->id.'/review', [...$review, 'requested_date' => '2030-01-31'])->assertSessionHasNoErrors();
        // The order form calendar ends at the same limit.
        $this->getJson('/order/jadwal?outlet_id='.$order->outlet_id.'&method=pickup')->assertOk()->assertJsonPath('days.29.date', '2030-01-31')->assertJsonCount(30, 'days');
    }

    public function test_the_21st_pickup_in_an_hour_and_the_31st_order_at_a_mall_are_refused(): void
    {
        $mall = Outlet::create(['code' => 'LW', 'name' => 'Living World', 'address' => 'Mall', 'is_active' => true, 'daily_quota' => 30]);
        $make = fn (int $count, array $attributes) => collect(range(1, $count))->each(fn () => $this->order(['order_status' => 'pending_review', 'payment_status' => 'not_created', ...$attributes]));
        $make(20, ['outlet_id' => $mall->id, 'requested_date' => '2030-01-05', 'requested_time' => '14:00']);
        $capacity = app(Capacity::class);
        $this->assertSame('Slot jam 14.00 di Living World sudah penuh. Pilih jam lain.', $capacity->problem($mall, '2030-01-05', '14:00', 'pickup'));
        $this->assertNull($capacity->problem($mall, '2030-01-05', '15:00', 'pickup'));
        // Delivery times are estimates: no hourly limit, only the daily quota.
        $this->assertNull($capacity->problem($mall, '2030-01-05', '14:00', 'delivery'));
        $day = collect($this->getJson('/order/jadwal?outlet_id='.$mall->id.'&method=pickup')->json('days'))->firstWhere('date', '2030-01-05');
        $this->assertSame(['open', ['time' => '14:00', 'left' => 0], ['time' => '15:00', 'left' => 20]], [$day['status'], $day['slots'][1], $day['slots'][2]]);

        $make(10, ['outlet_id' => $mall->id, 'requested_date' => '2030-01-05', 'requested_time' => '16:00', 'fulfillment_method' => 'delivery']);
        $this->assertSame('Kuota Living World untuk tanggal ini sudah penuh. Pilih tanggal atau outlet lain.', $capacity->problem($mall, '2030-01-05', '17:00', 'pickup'));
        $this->assertSame('full', collect($this->getJson('/order/jadwal?outlet_id='.$mall->id.'&method=pickup')->json('days'))->firstWhere('date', '2030-01-05')['status']);
        // Another outlet on the same date still has room; a cancelled order frees its place.
        $this->assertNull($capacity->problem(Outlet::where('code', 'TEST')->sole(), '2030-01-05', '14:00', 'pickup'));
        Order::where('outlet_id', $mall->id)->latest('id')->first()->update(['order_status' => 'cancelled']);
        $this->assertNull($capacity->problem($mall, '2030-01-05', '17:00', 'pickup'));
    }

    public function test_staff_choose_the_nearest_outlet_within_its_quota(): void
    {
        $order = $this->order(['order_status' => 'pending_review', 'payment_status' => 'not_created', 'fulfillment_method' => 'delivery', 'nearest_outlet' => true, 'delivery_fee' => null, 'requested_date' => '2030-01-05']);
        $pickupOnly = Outlet::create(['code' => 'PO', 'name' => 'Pickup Only', 'address' => 'A', 'is_active' => true]);
        $near = Outlet::create(['code' => 'NEAR', 'name' => 'Near Outlet', 'address' => 'B', 'is_active' => true, 'delivers' => true, 'daily_quota' => 1]);
        $this->order(['outlet_id' => $near->id, 'requested_date' => '2030-01-05']);
        $this->actingAs(User::factory()->create());
        $this->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('order.nearest_outlet', true)->has('outlets', 3));
        $review = ['review_version' => 0, 'delivery_fee' => 20000, 'note' => 'Nearest outlet'];
        // The suggested outlet is a placeholder: no review or confirmation until staff choose one.
        $this->post('/admin/orders/'.$order->id.'/review', [...$review, 'outlet_id' => ''])->assertSessionHasErrors('outlet_id');
        $this->post('/admin/orders/'.$order->id.'/review', $review)->assertSessionHasErrors('outlet_id');
        $order->update(['delivery_fee' => 20000]);
        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0]);
        $this->assertSame(['pending_review', true], [$order->fresh()->order_status, $order->fresh()->nearest_outlet]);
        $order->update(['delivery_fee' => null]);
        $this->post('/admin/orders/'.$order->id.'/review', [...$review, 'outlet_id' => $pickupOnly->id])->assertSessionHasErrors('outlet_id');
        $this->post('/admin/orders/'.$order->id.'/review', [...$review, 'outlet_id' => $near->id])->assertSessionHasErrors('requested_date');
        $near->update(['daily_quota' => 2]);
        $this->post('/admin/orders/'.$order->id.'/review', [...$review, 'outlet_id' => $near->id])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame([$near->id, 'Near Outlet', false, 20000], [$order->outlet_id, $order->outlet_name_snapshot, $order->nearest_outlet, $order->delivery_fee]);
    }

    public function test_staff_reschedule_a_paid_order_once_before_the_cutoff(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'requested_date' => '2030-01-04']);
        $unpaid = $this->order(['requested_date' => '2030-01-04']);
        $staff = User::factory()->create();
        $this->actingAs($staff)->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('reschedule.blocker', null));
        $move = ['requested_date' => '2030-01-06', 'requested_time' => '15:00', 'note' => 'Customer asked by WhatsApp'];
        $this->post('/admin/orders/'.$unpaid->id.'/reschedule', $move)->assertSessionHasErrors('reschedule');
        // Only the outlet's hourly slots.
        $this->post('/admin/orders/'.$order->id.'/reschedule', [...$move, 'requested_time' => '15:30'])->assertSessionHasErrors('requested_time');
        $this->post('/admin/orders/'.$order->id.'/reschedule', [...$move, 'requested_time' => '02:00'])->assertSessionHasErrors('requested_time');
        // The new date must have room at the same outlet.
        $order->outlet->update(['daily_quota' => 1]);
        $this->order(['requested_date' => '2030-01-06']);
        $this->post('/admin/orders/'.$order->id.'/reschedule', $move)->assertSessionHasErrors('requested_date');
        $order->outlet->update(['daily_quota' => 100]);

        $this->post('/admin/orders/'.$order->id.'/reschedule', $move)->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(['2030-01-06', '15:00', '2030-01-04', $order->outlet_id], [$order->requested_date->toDateString(), substr($order->requested_time, 0, 5), $order->rescheduled_from->toDateString(), $order->outlet_id]);
        $this->assertDatabaseHas('order_reviews', ['order_id' => $order->id, 'actor_id' => $staff->id, 'previous_schedule' => '2030-01-04 14:00', 'schedule' => '2030-01-06 15:00']);
        // Only once, and never again after the cut-off.
        $this->post('/admin/orders/'.$order->id.'/reschedule', [...$move, 'requested_date' => '2030-01-07'])->assertSessionHasErrors('reschedule');
        $late = $this->order(['payment_status' => 'paid', 'requested_date' => '2030-01-02']);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 19:01:00', 'Asia/Makassar'));
        $this->post('/admin/orders/'.$late->id.'/reschedule', $move)->assertSessionHasErrors('reschedule');
    }

    public function test_delivery_minimum_order_and_radius_come_from_the_schedule_settings(): void
    {
        $this->order();
        $outlet = Outlet::where('code', 'TEST')->sole();
        $outlet->update(['delivers' => true]);
        Setting::put(['delivery.min_order' => 100000, 'delivery.max_radius_km' => 12]);
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->where('deliveryMinOrder', 100000)->where('deliveryRadius', 12));
        $product = Product::sole();
        $payload = fn () => [
            'checkout_key' => session('checkout_key'), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'quoted_price' => 80000]], 'name' => 'Sinta', 'whatsapp' => '081234567890',
            'outlet_id' => $outlet->id, 'fulfillment_method' => 'delivery', 'requested_date' => '2030-01-03', 'requested_time' => '14:00', 'delivery_address' => 'Jl. Test',
            'recipient_whatsapp' => '081234567890', 'location_url' => 'https://maps.app.goo.gl/AbC123',
        ];
        $this->post('/order', $payload())->assertSessionHasErrors('fulfillment_method');
        $this->post('/order', [...$payload(), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'quoted_price' => 80000]]])->assertSessionHasNoErrors();
    }

    public function test_a_refunded_sale_already_in_erzap_is_listed_until_voided_there(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'order_status' => 'cancelled']);
        IntegrationSync::create(['provider' => 'erzap', 'type' => 'order', 'subject_type' => 'orders', 'subject_id' => $order->id, 'sync_key' => 'erzap:test:'.$order->id, 'status' => 'synced', 'attempts' => 1]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/integrations')->assertInertia(fn (Assert $page) => $page->has('toVoid', 1)->where('toVoid.0.order_code', $order->order_code));
        $this->post('/admin/orders/'.$order->id.'/erzap-voided')->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->erzap_voided_at);
        $this->get('/admin/integrations')->assertInertia(fn (Assert $page) => $page->has('toVoid', 0));
        // A completed sale refunded later (damaged product) is listed too; an order never synced is not.
        $refunded = $this->order(['payment_status' => 'refunded', 'order_status' => 'completed']);
        IntegrationSync::create(['provider' => 'erzap', 'type' => 'order', 'subject_type' => 'orders', 'subject_id' => $refunded->id, 'sync_key' => 'erzap:test:'.$refunded->id, 'status' => 'synced', 'attempts' => 1]);
        $this->order(['payment_status' => 'refunded', 'order_status' => 'completed']);
        $this->get('/admin/integrations')->assertInertia(fn (Assert $page) => $page->has('toVoid', 1)->where('toVoid.0.order_code', $refunded->order_code));
        $this->post('/admin/orders/'.$refunded->id.'/erzap-voided')->assertSessionHasNoErrors();
        $this->post('/admin/orders/'.$refunded->id.'/erzap-voided')->assertStatus(422);
    }

    public function test_admin_tables_sort_by_a_column_header(): void
    {
        $cheap = $this->order(['total' => 50000, 'requested_date' => '2030-01-05']);
        $dear = $this->order(['total' => 900000, 'requested_date' => '2030-01-03']);
        $cheap->customer->update(['name' => 'Zara']);
        $dear->customer->update(['name' => 'Ayu']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/orders?sort=total&dir=desc')->assertInertia(fn (Assert $page) => $page->where('orders.data.0.id', $dear->id)->where('sort', ['sort' => 'total', 'dir' => 'desc']));
        $this->get('/admin/orders?sort=total&dir=asc')->assertInertia(fn (Assert $page) => $page->where('orders.data.0.id', $cheap->id));
        $this->get('/admin/orders?sort=customer')->assertInertia(fn (Assert $page) => $page->where('orders.data.0.customer.name', 'Ayu')->where('sort.dir', 'asc'));
        $this->get('/admin/orders?sort=date&dir=asc&status=confirmed')->assertInertia(fn (Assert $page) => $page->where('orders.data.0.id', $dear->id)->where('filters.status', 'confirmed'));
        // Products: by price, highest first; the default stays by name.
        Product::where('id', '!=', $dear->items()->first()->product_id)->update(['price' => 10000]);
        $this->get('/admin/products?sort=price&dir=desc')->assertInertia(fn (Assert $page) => $page->where('records.data.0.price', 80000));
        $this->get('/admin/products')->assertInertia(fn (Assert $page) => $page->where('sort', ['sort' => null, 'dir' => null]));
    }

    public function test_orders_waiting_more_than_an_hour_are_flagged(): void
    {
        $this->order(['order_status' => 'pending_review', 'payment_status' => 'not_created']);
        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('orders.overdue_review', 0));
        $this->travel(61)->minutes();
        $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('orders.overdue_review', 1));
    }
}
