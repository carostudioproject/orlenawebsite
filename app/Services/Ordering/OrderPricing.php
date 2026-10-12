<?php

namespace App\Services\Ordering;

use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class OrderPricing
{
    /**
     * Prices a selection of {product_id, quantity, quoted_price, contents?} for an outlet.
     * Hampers and boxes carry the chosen contents for one unit (product IDs, repeats allowed); each returned item then has
     * a "contents" list with the total quantity per content product. quoted_price is the price of one unit including
     * contents; it only detects stale prices, all totals use database prices.
     */
    public function quote(array $selection, Outlet $outlet): array
    {
        $ids = array_merge(array_column($selection, 'product_id'), ...array_map(fn ($row) => array_map('intval', $row['contents'] ?? []), $selection));
        $products = Product::with('category', 'contentOptions:id')->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $items = [];
        foreach ($selection as $index => $row) {
            $product = $products->get($row['product_id']);
            if (! $product?->is_active || ! $product->price || ! $product->category->is_active || ! $product->onSale()) {
                $this->fail("items.$index.product_id", 'Produk ini belum tersedia untuk PO. Pilih produk lain.');
            }
            $quantity = (int) $row['quantity'];
            $price = $product->priceAt($outlet);
            $contents = $this->contents($product, array_map('intval', $row['contents'] ?? []), $products, $outlet, $quantity, $index);
            $unitTotal = $price + array_sum(array_map(fn ($content) => $content['unit_price'] * $content['per_unit'], $contents));
            if ($price < 1 || $unitTotal !== (int) $row['quoted_price']) {
                $this->fail('pricing', 'Harga produk berubah. Muat ulang harga dan periksa total sebelum mengirim kembali.');
            }
            $items[] = [...$this->line($product, $price, $quantity), 'contents' => $contents];
        }
        $subtotal = array_sum(array_map(fn ($item) => $item['subtotal'] + array_sum(array_column($item['contents'], 'subtotal')), $items));

        return ['items' => $items, 'subtotal' => $subtotal];
    }

    /** Validates the contents chosen for one unit and merges repeats: [line + per_unit] per content product. */
    private function contents(Product $product, array $chosen, $products, Outlet $outlet, int $quantity, int $index): array
    {
        if (! $product->hasChoice()) {
            if ($chosen) {
                $this->fail("items.$index.contents", 'Produk ini tidak memiliki pilihan isi.');
            }

            return [];
        }
        $label = $product->content_type === 'hampers' ? 'isi hampers' : 'isi box';
        if (count($chosen) !== $product->content_count) {
            $this->fail("items.$index.contents", 'Pilih '.$product->content_count.' '.$label.' untuk '.$product->label().'.');
        }
        $allowed = $product->contentOptions->pluck('id')->all();
        $lines = [];
        foreach (array_count_values($chosen) as $id => $perUnit) {
            $content = $products->get($id);
            if (! $content || ! in_array($id, $allowed, true) || ! $content->availableAsContentOf($product)) {
                $this->fail("items.$index.contents", 'Pilihan '.$label.' untuk '.$product->label().' sudah tidak tersedia. Pilih isi lain.');
            }
            $price = $product->content_pricing === 'added' ? $content->priceAt($outlet) : 0;
            $lines[] = [...$this->line($content, $price, $perUnit * $quantity), 'per_unit' => $perUnit];
        }

        return $lines;
    }

    private function line(Product $product, int $price, int $quantity): array
    {
        return ['product_id' => $product->id, 'name' => $product->name, 'category' => $product->category->name, 'variant' => $product->variant,
            'sku' => $product->sku, 'unit_price' => $price, 'quantity' => $quantity, 'subtotal' => $price * $quantity];
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
