<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClosedDate;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\PreorderDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleAndCatalogTest extends TestCase
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
        // Tuesday.
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
        $category = Category::create(['name' => 'Brownies']);
        $this->product = Product::create(['category_id' => $category->id, 'sku' => 'B-1', 'name' => 'Berry', 'price' => 80000, 'is_active' => true]);
        $this->outlet = Outlet::create(['code' => 'O', 'name' => 'Test Outlet', 'address' => 'Jl', 'is_active' => true, 'delivers' => true]);
    }

    private function place(string $date, ?Product $product = null)
    {
        $product ??= $this->product;
        $this->get('/order?outlet_id='.$this->outlet->id);

        return $this->post('/order', [
            'checkout_key' => session('checkout_key'), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'quoted_price' => $product->price]],
            'name' => 'Customer', 'whatsapp' => '081234567890', 'outlet_id' => $this->outlet->id, 'fulfillment_method' => 'pickup',
            'requested_date' => $date, 'requested_time' => '14:00',
        ]);
    }

    private function dateError(string $date): string
    {
        $this->place($date)->assertSessionHasErrors('requested_date');

        return session('errors')->first('requested_date');
    }

    public function test_default_cutoff_is_h_minus_one_nineteen_hundred_wita_inclusive(): void
    {
        $date = app(PreorderDate::class);
        $this->assertSame('2026-09-24', $date->earliest(CarbonImmutable::parse('2026-09-23 18:59:59', 'Asia/Makassar')));
        $this->assertSame('2026-09-24', $date->earliest(CarbonImmutable::parse('2026-09-23 19:00:00', 'Asia/Makassar')));
        $this->assertSame('2026-09-25', $date->earliest(CarbonImmutable::parse('2026-09-23 19:00:01', 'Asia/Makassar')));
        $this->assertSame('2027-01-02', $date->earliest(CarbonImmutable::parse('2026-12-31 11:00:01', 'UTC')));
        $this->assertSame(['H-1 pukul 19.00 WITA', 'H-1 pukul 21.00 WITA'], [$date->cutoffLabel(), $date->paymentCloseLabel()]);
        // Rules Order Website: at most 30 days ahead.
        $this->assertSame('2026-10-23', $date->latest(CarbonImmutable::parse('2026-09-23 22:00:00', 'Asia/Makassar')));
    }

    public function test_staff_set_cutoff_closed_weekdays_and_closed_dates_that_customers_cannot_pick(): void
    {
        $staff = User::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/admin/schedule')->assertForbidden();
        $this->actingAs($staff)->get('/admin/schedule')->assertInertia(fn (Assert $page) => $page->component('Admin/Schedule')
            ->where('settings.cutoff_time', '19:00')->where('settings.payment_close_time', '21:00')->where('settings.max_days', 30)->where('earliest', '2030-01-02')->where('poCutoff', 'D-1 at 19:00 WITA')->where('weekdays.0', ['value' => 1, 'label' => 'Monday']));

        $rules = ['cutoff_time' => '11:00', 'payment_close_time' => '13:00', 'max_days' => 30, 'delivery_tolerance' => 45];
        $this->put('/admin/schedule', [...$rules, 'payment_close_time' => '10:59', 'closed_weekdays' => [3]])->assertSessionHasErrors('payment_close_time');
        $this->put('/admin/schedule', [...$rules, 'closed_weekdays' => [3]])->assertSessionHasNoErrors();
        $this->put('/admin/schedule', [...$rules, 'closed_weekdays' => [0, 1, 2, 3, 4, 5, 6]])->assertSessionHasErrors('closed_weekdays');
        $this->post('/admin/schedule/closed-dates', ['date' => '2030-01-03', 'reason' => 'Libur'])->assertSessionHasNoErrors();
        $this->post('/admin/schedule/closed-dates', ['date' => '2030-01-03'])->assertSessionHasErrors('date');
        $this->assertSame(['H-1 pukul 11.00 WITA', 45], [app(PreorderDate::class)->cutoffLabel(), app(PreorderDate::class)->deliveryTolerance()]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.schedule_updated', 'actor_id' => $staff->id]);

        // 12:00 is past today's 11:00 cutoff (so not Wed 2nd, also closed weekly); Thu 3rd is closed; Fri 4th is the first open date.
        $this->assertSame('2030-01-04', app(PreorderDate::class)->earliest());
        auth()->logout();
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->where('poCutoff', 'H-1 pukul 11.00 WITA'));
        // The calendar disables each date with its reason; Friday 4th is open with hourly slots.
        $days = collect($this->getJson('/order/jadwal?outlet_id='.$this->outlet->id.'&method=pickup')->assertOk()->json('days'))->keyBy('date');
        $this->assertSame(['cutoff', 'closed', 'Tanggal ini tutup untuk PO (Libur).', 'open', ['time' => '13:00', 'left' => 20]],
            [$days['2030-01-02']['status'], $days['2030-01-03']['status'], $days['2030-01-03']['reason'], $days['2030-01-04']['status'], $days['2030-01-04']['slots'][0]]);
        $this->assertSame('closed', $days['2030-01-09']['status']);
        $this->assertStringContainsString('batas pemesanan H-1 pukul 11.00 WITA', $this->dateError('2030-01-02'));
        $this->assertStringContainsString('hari Rabu', $this->dateError('2030-01-09'));
        $this->place('2030-01-10')->assertSessionHasNoErrors();

        ClosedDate::create(['date' => '2030-01-11', 'reason' => 'Nyepi']);
        $this->assertSame('Tanggal ini tutup untuk PO (Nyepi). Pilih tanggal lain.', $this->dateError('2030-01-11'));
        $this->assertSame(1, Order::count());

        // Staff see the outlet's load against its daily quota, and closed dates, on the order page.
        $this->outlet->update(['daily_quota' => 1]);
        $order = Order::sole();
        $this->actingAs($staff)->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('dayLoad', ['orders' => 1, 'capacity' => 1, 'closed' => null]));
        $closed = ClosedDate::create(['date' => '2030-01-10']);
        $this->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('dayLoad.closed', 'This date is closed for PO.'));
        $this->delete('/admin/schedule/closed-dates/'.$closed->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('closed_dates', ['id' => $closed->id]);
    }

    public function test_free_text_variants_hampers_and_sale_periods(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Hampers']);
        $base = ['category_id' => $category->id, 'name' => 'Hampers Lebaran', 'price' => 350000, 'is_active' => true, 'is_hamper' => true];
        $this->actingAs($admin)->post('/admin/products', [...$base, 'sku' => 'H-1', 'variant' => 'Box isi 6', 'hamper_contents' => ''])->assertSessionHasErrors('hamper_contents');
        // "Tambah hampers" opens the product form in hamper mode; saving returns to the Hampers list.
        $this->get('/admin/products/create?type=hampers')->assertInertia(fn (Assert $page) => $page->where('hamperMode', true));
        $this->get('/admin/products/create')->assertInertia(fn (Assert $page) => $page->where('hamperMode', false));
        $this->post('/admin/products', [...$base, 'sku' => 'H-1', 'variant' => 'Box isi 6', 'hamper_contents' => "1x Brownies Berry\n\n1x Nutella", 'sale_starts_on' => '2030-01-01', 'sale_ends_on' => '2030-01-05'])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/products?type=hampers');
        $this->post('/admin/products', [...$base, 'sku' => 'H-2', 'variant' => 'Box isi 6', 'hamper_contents' => 'x'])->assertSessionHasErrors('variant');
        $this->post('/admin/products', [...$base, 'sku' => 'H-3', 'variant' => 'Box isi 9', 'hamper_contents' => 'x', 'sale_starts_on' => '2030-01-05', 'sale_ends_on' => '2030-01-01'])->assertSessionHasErrors('sale_ends_on');
        $hamper = Product::where('sku', 'H-1')->sole();
        $this->assertSame(['1x Brownies Berry', '1x Nutella'], $hamper->hamperItems());
        $this->get('/admin/products?type=hampers')->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.variant', 'Box isi 6'));
        $this->get('/admin/products/'.$hamper->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('hamperMode', true));

        // Turning a product back into a regular product clears its contents.
        $this->put('/admin/products/'.$this->product->id, ['category_id' => $this->product->category_id, 'name' => 'Berry', 'sku' => 'B-1', 'price' => 80000, 'is_active' => true, 'variant' => 'Fullsize', 'is_hamper' => false, 'hamper_contents' => 'ignored'])->assertSessionHasNoErrors();
        $this->assertSame(['Fullsize', null], [$this->product->fresh()->variant, $this->product->fresh()->hamper_contents]);

        auth()->logout();
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->where('products', fn ($products) => collect($products)->firstWhere('id', $hamper->id)['hamper_items'] === ['1x Brownies Berry', '1x Nutella']
            && collect($products)->firstWhere('id', $hamper->id)['sale_ends_on'] === '2030-01-05' && collect($products)->firstWhere('id', $hamper->id)['variant'] === 'Box isi 6'));
        $this->place('2030-01-03', $hamper)->assertSessionHasNoErrors();
        $this->assertSame('Box isi 6', Order::sole()->items->sole()->variant_snapshot);

        // After the sale period the hamper disappears from the form and cannot be ordered.
        $this->travelTo(CarbonImmutable::parse('2030-01-06 09:00:00', 'Asia/Makassar'));
        $this->flushSession();
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->where('products', fn ($products) => ! collect($products)->contains('id', $hamper->id)));
        $this->place('2030-01-08', $hamper)->assertSessionHasErrors('items.0.product_id');
    }
}
