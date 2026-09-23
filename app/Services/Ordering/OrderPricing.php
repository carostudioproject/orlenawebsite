<?php

namespace App\Services\Ordering;

use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class OrderPricing
{
    public function quote(array $selection, Outlet $outlet): array
    {
        $products = Product::with('category')->whereIn('id', array_column($selection, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $items = [];
        foreach ($selection as $index => $row) {
            $product = $products->get($row['product_id']);
            if (! $product?->is_active || ! $product->price || ! $product->category->is_active || ! $product->onSale()) {
                throw ValidationException::withMessages(["items.$index.product_id" => 'Produk ini belum tersedia untuk PO. Pilih produk lain.']);
            }
            $price = $product->priceAt($outlet);
            // Quoted price is only a stale-price check; all totals use the database price.
            if ($price < 1 || $price !== (int) $row['quoted_price']) {
                throw ValidationException::withMessages(['pricing' => 'Harga produk berubah. Muat ulang harga dan periksa total sebelum mengirim kembali.']);
            }
            $items[] = ['product_id' => $product->id, 'name' => $product->name, 'category' => $product->category->name, 'variant' => $product->variant,
                'sku' => $product->sku, 'unit_price' => $price, 'quantity' => (int) $row['quantity'], 'subtotal' => $price * $row['quantity']];
        }

        return ['items' => $items, 'subtotal' => array_sum(array_column($items, 'subtotal'))];
    }
}
