<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductCatalogSeeder extends Seeder
{
    private const SIZE_CATEGORIES = ['FULLSIZE BROWNIES' => ['Brownies', 'Fullsize'], 'HALFSIZE BROWNIES' => ['Brownies', 'Halfsize']];

    public function run(): void
    {
        $rows = json_decode(file_get_contents(resource_path('content/product-catalog.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                // The owner's table lists sizes as categories; they are variants of Brownies.
                [$categoryName, $variant] = self::SIZE_CATEGORIES[$row['category']] ?? [$row['category'], null];
                // Product-only groupings (e.g. sauces) stay off the homepage until chosen under website content.
                $category = Category::firstOrCreate(['name' => $categoryName]);
                if ($category->wasRecentlyCreated) {
                    Audit::record('catalog.imported', $category, ['name' => $category->name, 'source' => 'owner-product-table-2026-09-23']);
                }
                $product = Product::firstOrCreate(['sku' => $row['sku']], [
                    'category_id' => $category->id,
                    'variant' => $variant,
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'description' => $row['description'],
                    'is_active' => false,
                ]);
                if ($product->wasRecentlyCreated) {
                    Audit::record('catalog.imported', $product, [...$product->only('sku', 'name', 'category_id', 'price', 'description', 'is_active'), 'source' => 'owner-product-table-2026-09-23']);
                }
            }
        });
    }
}
