<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\Product;

/** Orderable categories and products, priced for the outlet that fulfils the order (base price when none is chosen yet). */
class OrderCatalog
{
    public static function for(?Outlet $pricing): array
    {
        $products = Product::with('category', 'outletPrices')->where('is_active', true)->where('price', '>', 0)->onSale()
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->orderByDesc('price')->get();

        return [
            'categories' => $products->pluck('category')->unique('id')->sortBy([['order_position', 'asc'], ['name', 'asc']])->values()
                ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name, 'image' => $category->image]),
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id, 'name' => $product->name, 'category_id' => $product->category_id, 'description' => $product->description,
                'image' => $product->image, 'variant' => $product->variant,
                'is_hamper' => $product->is_hamper, 'hamper_items' => $product->hamperItems(), 'sale_ends_on' => $product->sale_ends_on?->toDateString(),
                'price' => $pricing ? ($product->outletPrices->firstWhere('outlet_id', $pricing->id)?->price ?? $product->price) : $product->price,
            ]),
        ];
    }
}
