<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AntiSpamTest extends TestCase
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
        $category = Category::create(['name' => 'Brownies']);
        $product = Product::create(['category_id' => $category->id, 'sku' => 'BR-1', 'name' => 'Test Brownie', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::create(['code' => 'OUT', 'name' => 'Test Outlet', 'address' => 'Outlet address', 'is_active' => true]);

        return [$product, $outlet];
    }

    /** Opens the form (a fresh session key each time) and returns a valid order for it. */
    private function form(Product $product, Outlet $outlet, string $whatsapp = '081234567890'): array
    {
        session()->forget(['checkout_key', 'checkout_issued_at']);
        $this->get('/order?outlet_id='.$outlet->id)->assertOk();

        return [
            'checkout_key' => session('checkout_key'), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'quoted_price' => 80000]],
            'name' => 'Pelanggan', 'whatsapp' => $whatsapp, 'outlet_id' => $outlet->id, 'fulfillment_method' => 'pickup',
            'requested_date' => '2030-01-02', 'requested_time' => '14:00',
        ];
    }

    public function test_bots_filling_the_hidden_field_or_sending_in_seconds_are_refused(): void
    {
        [$product, $outlet] = $this->catalog();
        $this->post('/order', [...$this->form($product, $outlet), 'website' => 'https://spam.example'])->assertSessionHasErrors('website');

        config(['site.checkout_min_seconds' => 5]);
        $order = $this->form($product, $outlet);
        $this->post('/order', $order)->assertSessionHasErrors('checkout');
        $this->travel(6)->seconds();
        $this->post('/order', $order)->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }

    public function test_one_whatsapp_number_cannot_hold_many_unpaid_orders(): void
    {
        [$product, $outlet] = $this->catalog();
        // A minute between orders, so the per-minute limit per address is not what stops them.
        $this->app['events']->listen(RequestHandled::class, fn () => $this->travel(61)->seconds());
        for ($i = 0; $i < 3; $i++) {
            $this->post('/order', $this->form($product, $outlet))->assertSessionHasNoErrors();
        }
        // The same number written another way (+62) is the same customer.
        $this->post('/order', $this->form($product, $outlet, '+62 812-3456-7890'))->assertSessionHasErrors('whatsapp');
        $this->assertSame(3, Order::count());

        // A paid or cancelled order frees a place; five a day stays the limit.
        Order::query()->limit(2)->update(['order_status' => 'cancelled']);
        $this->post('/order', $this->form($product, $outlet))->assertSessionHasNoErrors();
        $this->post('/order', $this->form($product, $outlet))->assertSessionHasNoErrors();
        Order::query()->update(['order_status' => 'cancelled']);
        $this->post('/order', $this->form($product, $outlet))->assertSessionHasErrors('whatsapp');
        $this->assertSame(5, Order::count());
        // Another number is not affected.
        $this->post('/order', $this->form($product, $outlet, '081299998888'))->assertSessionHasNoErrors();
    }

    public function test_order_floods_from_one_address_are_capped_per_hour(): void
    {
        [$product, $outlet] = $this->catalog();
        $statuses = [];
        for ($i = 0; $i < 16; $i++) {
            if ($i % 5 === 0) {
                $this->travel(61)->seconds();
            }
            $statuses[] = $this->post('/order', [...$this->form($product, $outlet, '0812'.str_pad((string) $i, 8, '0', STR_PAD_LEFT))])->getStatusCode();
        }
        $this->assertSame([302, 429], [$statuses[14], $statuses[15]]);
    }

    public function test_an_account_locks_after_failed_logins_from_many_addresses(): void
    {
        User::factory()->create(['username' => 'tiwi', 'password' => Hash::make('CorrectPassword123')]);
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])->post('/admin/login', ['username' => 'tiwi', 'password' => 'wrong-'.$i])->assertSessionHasErrors('username');
        }
        // Even the right password from a new address waits until the lock ends.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->post('/admin/login', ['username' => 'tiwi', 'password' => 'CorrectPassword123'])
            ->assertSessionHasErrors(['username' => 'Too many attempts. Try again in 15 minute(s).']);
        $this->assertGuest();
        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->post('/admin/login', ['username' => 'tiwi', 'password' => 'CorrectPassword123'])->assertRedirect('/admin');
    }

    public function test_responses_do_not_reveal_the_php_version(): void
    {
        $this->get('/admin/login')->assertHeaderMissing('X-Powered-By');
    }
}
