<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\Product;

/**
 * Orderable categories and products, priced for the outlet that fulfils the order (base price when none is chosen yet).
 * Hampers and boxes list the contents the customer can choose, with the price each content adds (0 for a box).
 */
class OrderCatalog
{
    public static function for(?Outlet $pricing): array
    {
        $products = Product::with('category', 'outletPrices', 'contentOptions.category', 'contentOptions.outletPrices')
            ->where('is_active', true)->where('price', '>', 0)->onSale()
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->orderByDesc('price')->get();
        $price = fn (Product $product) => $pricing ? ($product->outletPrices->firstWhere('outlet_id', $pricing->id)?->price ?? $product->price) : $product->price;

        $rows = $products->map(fn (Product $product) => [
            'id' => $product->id, 'name' => $product->name, 'category_id' => $product->category_id, 'description' => $product->description,
            'image' => $product->image, 'variant' => $product->variant,
            'is_hamper' => $product->is_hamper || $product->content_type === 'hampers', 'hamper_items' => $product->hamperItems(), 'sale_ends_on' => $product->sale_ends_on?->toDateString(),
            'price' => $price($product),
            'content_type' => $product->hasChoice() || $product->hasFixedContents() ? $product->content_type : null,
            'content_count' => $product->hasChoice() ? $product->content_count : ($product->hasFixedContents() ? (int) $product->contentOptions->sum('pivot.quantity') : null),
            // A fixed box lists what it holds; it has no choice to make.
            'fixed_contents' => $product->hasFixedContents() ? $product->contentOptions->sortBy('name')->values()
                ->map(fn (Product $option) => ['id' => $option->id, 'name' => $option->name, 'variant' => $option->variant, 'image' => $option->image, 'quantity' => $option->pivot->quantity])->all() : [],
            'contents' => $product->hasChoice() ? $product->contentOptions->filter(fn (Product $option) => $option->availableAsContentOf($product))
                ->sortBy([['name', 'asc'], ['variant', 'asc']])->values()
                ->map(fn (Product $option) => [
                    'id' => $option->id, 'name' => $option->name, 'variant' => $option->variant, 'image' => $option->image,
                    'price' => $product->content_pricing === 'added' ? $price($option) : 0,
                ])->all() : [],
        ])
            // A hampers or box without any available contents cannot be ordered.
            ->filter(fn (array $row) => ! in_array($row['content_type'], ['hampers', 'box'], true) || $row['contents'])->values();
        $categoryIds = $rows->pluck('category_id')->unique();

        return [
            'categories' => $products->pluck('category')->whereIn('id', $categoryIds)->unique('id')->sortBy([['order_position', 'asc'], ['name', 'asc']])->values()
                ->map(fn ($category) => ['id' => $category->id, 'name' => $category->name, 'image' => $category->image]),
            'products' => $rows,
        ];
    }
}
