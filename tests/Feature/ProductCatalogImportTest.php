<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only the isolated orlena_test database is allowed.');
        }
    }

    public function test_owner_catalog_is_imported_exactly_with_sizes_as_variants(): void
    {
        $this->seed(ProductCatalogSeeder::class);
        $this->assertDatabaseCount('products', 36);
        // Fullsize/halfsize from the owner's table become variants of the same Brownies product names.
        $brownies = Category::where('name', 'Brownies')->value('id');
        $this->assertSame(14, Product::where('category_id', $brownies)->where('variant', 'Fullsize')->count());
        $this->assertSame(14, Product::where('category_id', $brownies)->where('variant', 'Halfsize')->count());
        $this->assertSame(8, Product::where('category_id', Category::where('name', 'SAUCE')->value('id'))->whereNull('variant')->count());
        $this->assertSame(Product::where('variant', 'Fullsize')->orderBy('name')->pluck('name')->all(), Product::where('variant', 'Halfsize')->orderBy('name')->pluck('name')->all());
        $this->assertSame(2063000, (int) Product::sum('price'));
        $this->assertDatabaseHas('products', ['sku' => 'ORL-FB-010', 'name' => 'Berry Crumble Cheesecake', 'price' => 115000]);
        $this->assertDatabaseHas('products', ['sku' => 'ORL-HB-014', 'name' => 'Ovomaltine', 'price' => 65000]);
        $this->assertDatabaseHas('products', ['sku' => 'ORL-SC-008', 'name' => 'Pistachio kunafa', 'price' => 18000, 'description' => null]);
        $this->assertDatabaseHas('products', ['sku' => 'ORL-FB-003', 'name' => 'Bluberry Cheese', 'description' => 'Brownies fudge dengan topping kitkat choco dan chocochips']);
        $this->assertSame(0, Product::where('is_active', true)->count());
        $this->assertDatabaseCount('audit_logs', 38);
    }

    public function test_rerunning_import_does_not_duplicate_or_overwrite_dashboard_edits(): void
    {
        $this->seed(ProductCatalogSeeder::class);
        Product::where('sku', 'ORL-FB-001')->update(['price' => 81000, 'is_active' => true, 'description' => 'Owner revised copy']);
        $this->seed(ProductCatalogSeeder::class);
        $this->assertDatabaseCount('products', 36);
        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseCount('audit_logs', 38);
        $this->assertDatabaseHas('products', ['sku' => 'ORL-FB-001', 'price' => 81000, 'is_active' => true, 'description' => 'Owner revised copy']);
        $this->assertSame(0, DB::table('outlet_prices')->count());
    }
}
