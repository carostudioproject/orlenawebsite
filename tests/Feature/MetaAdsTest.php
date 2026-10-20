<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MetaAdsTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL = '123456789012345';

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

    private function meta(array $settings = []): void
    {
        config(['services.meta' => [...config('services.meta'), 'enabled' => true, 'pixel_id' => self::PIXEL, ...$settings]]);
    }

    public function test_the_pixel_loads_only_where_allowed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
        $category = Category::create(['name' => 'Brownies']);
        Product::create(['category_id' => $category->id, 'sku' => 'BR-1', 'name' => 'Test Brownie', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::create(['code' => 'OUT', 'name' => 'Test Outlet', 'address' => 'Outlet address', 'is_active' => true]);

        // Off, or on without an ID: nothing loads.
        $this->get('/about')->assertDontSee('meta-pixel.js', false);
        $this->meta(['pixel_id' => '']);
        $this->get('/about')->assertDontSee('meta-pixel.js', false);

        // Public pages with the ID from the settings (no ID written in the code); order pages only with META_PIXEL_ORDER_PAGES.
        $this->meta();
        $this->get('/about')->assertSee('data-pixel-id="'.self::PIXEL.'"', false)->assertDontSee('787759320264256', false);
        $this->get('/order?outlet_id='.$outlet->id)->assertDontSee('meta-pixel.js', false)
            ->assertInertia(fn (Assert $page) => $page->where('metaLeadEvent', null));
        $this->meta(['order_pages' => true, 'lead_event' => 'SubmitApplication']);
        $this->get('/order?outlet_id='.$outlet->id)->assertSee('data-pixel-id="'.self::PIXEL.'"', false)
            ->assertInertia(fn (Assert $page) => $page->where('metaLeadEvent', 'SubmitApplication'));
        // Never on order tracking, payment or the dashboard.
        $this->get('/cek-pesanan')->assertDontSee('meta-pixel.js', false);
        $this->get('/admin/login')->assertDontSee('meta-pixel.js', false);
    }
}
