<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ContentEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CatalogTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Moving the catalog, content and settings to the client's server at go-live, without test orders or users. */
class CatalogTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $file = 'storage/app/private/catalog-transfer-test.json';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->file));
        parent::tearDown();
    }

    public function test_the_catalog_moves_with_its_ids_and_without_orders_or_staff(): void
    {
        $editor = User::factory()->create();
        $category = Category::create(['name' => 'Brownies']);
        $outlet = Outlet::create(['code' => 'LW', 'name' => 'Living World', 'address' => 'Mall', 'is_active' => true, 'daily_quota' => 30, 'delivers' => true]);
        $flavour = Product::create(['category_id' => $category->id, 'sku' => 'MN1', 'name' => 'Mini Choco', 'price' => 0, 'is_active' => false]);
        $box = Product::create(['category_id' => $category->id, 'sku' => 'MB1', 'name' => 'Mini Box', 'price' => 60000, 'is_active' => true, 'content_type' => 'box', 'content_count' => 4, 'content_pricing' => 'included']);
        $box->contentOptions()->sync([$flavour->id => ['quantity' => 1]]);
        DB::table('outlet_prices')->insert(['product_id' => $box->id, 'outlet_id' => $outlet->id, 'price' => 65000, 'created_at' => now(), 'updated_at' => now()]);
        ContentEntry::create(['key' => 'hero', 'value' => ['title' => 'Halo'], 'updated_by' => $editor->id]);
        Setting::put(['po.cutoff_time' => '19:00', 'ck.whatsapp' => '6281234567890']);

        $this->artisan('orlena:export-catalog', ['path' => $this->file])->assertSuccessful();
        $export = json_decode(file_get_contents(base_path($this->file)), true);
        $this->assertSame(CatalogTransfer::TABLES, array_keys($export['tables']));
        $this->assertNull($export['tables']['content_entries'][0]['updated_by']);

        // The new server: migrated and seeded, no staff yet.
        foreach (array_reverse(CatalogTransfer::TABLES) as $table) {
            DB::table($table)->delete();
        }
        Category::create(['name' => 'Seeded']);
        $this->artisan('orlena:import-catalog', ['path' => $this->file])->assertFailed();
        $this->artisan('orlena:import-catalog', ['path' => $this->file, '--replace' => true])->assertSuccessful();

        $this->assertSame(['Brownies'], Category::pluck('name')->all());
        $this->assertSame([30, true], [Outlet::find($outlet->id)->daily_quota, Outlet::find($outlet->id)->delivers]);
        $this->assertSame([$flavour->id], Product::find($box->id)->contentOptions()->pluck('products.id')->all());
        $this->assertSame(65000, Product::find($box->id)->priceAt($outlet));
        $this->assertSame('6281234567890', Setting::get('ck.whatsapp'));
        $this->assertNull(ContentEntry::sole()->updated_by);
    }

    public function test_the_import_refuses_a_shop_that_already_has_orders(): void
    {
        $this->artisan('orlena:export-catalog', ['path' => $this->file])->assertSuccessful();
        $outlet = Outlet::create(['code' => 'T', 'name' => 'Test', 'address' => 'A', 'is_active' => true]);
        Order::create([
            'order_code' => 'ORL-'.Str::random(10), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64),
            'customer_id' => Customer::create(['name' => 'A', 'whatsapp' => '6281234567890'])->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => 'Test',
            'fulfillment_method' => 'pickup', 'requested_date' => '2030-01-02', 'subtotal' => 1, 'delivery_fee' => 0, 'total' => 1, 'order_status' => 'pending_review', 'payment_status' => 'not_created',
        ]);
        $this->artisan('orlena:import-catalog', ['path' => $this->file, '--replace' => true])->expectsOutputToContain('already has orders')->assertFailed();
        $this->assertSame(1, Outlet::count());
    }
}
