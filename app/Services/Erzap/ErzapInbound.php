<?php

namespace App\Services\Erzap;

use App\Actions\Integrations\ErzapSync;
use App\Models\Category;
use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\Product;
use App\Support\Audit;
use App\Support\ContentImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Data Erzap sends to the website (Erzap API v1.2, section 2). Products are matched by barcode only.
 *
 * Same ownership rule as the Excel import (ProductImport): Erzap owns the selling price and barcode; the website owns
 * the name, photo, description, category and size of existing products, and whether a product is shown. New products
 * are created inactive so staff add a photo and choose what is sold before they appear. Stock is not used.
 */
class ErzapInbound
{
    /** check_pesanan: at most this many orders per call, oldest first. */
    public const PULL_LIMIT = 20;

    public function __construct(private ProductImport $import, private ErzapSync $sync) {}

    /** import_produk_json: creates (when allowed) or updates products. Returns [created, updated, skipped notes]. */
    public function products(array $items): array
    {
        $created = $updated = 0;
        $notes = [];
        foreach ($items as $item) {
            $barcode = $this->barcode($item);
            if ($barcode === null) {
                $notes[] = 'produk tanpa barcode dilewati';

                continue;
            }
            DB::transaction(function () use ($item, $barcode, &$created, &$updated, &$notes) {
                $product = Product::where('barcode', $barcode)->lockForUpdate()->first();
                $price = $this->price($item);
                if (! $product) {
                    if (! filter_var($item['create_data_if_not_exist'] ?? true, FILTER_VALIDATE_BOOL)) {
                        return;
                    }
                    $name = $this->text($item['nama_web'] ?? null, 160) ?? $this->text($item['nama'] ?? null, 160);
                    if ($name === null) {
                        $notes[] = $barcode.': tanpa nama';

                        return;
                    }
                    $categoryName = $this->text($item['kategori_nama'] ?? null, 120) ?? 'Imported from Erzap';
                    $category = Category::whereRaw('LOWER(name) = ?', [mb_strtolower($categoryName)])->first()
                        ?? Category::create(['name' => $categoryName, 'is_active' => true]);
                    $product = Product::create([
                        'category_id' => $category->id, 'name' => $name, 'variant' => $this->text($item['produk_ukuran_nama'] ?? null, 40),
                        'barcode' => $barcode, 'sku' => $this->import->uniqueSku($this->text($item['kode'] ?? null, 80) ?? $barcode), 'price' => $price,
                        'description' => $this->text(strip_tags((string) ($item['overview'] ?? $item['deskripsi_produk'] ?? '')), 2000), 'is_active' => false,
                    ]);
                    Audit::record('catalog.erzap_api_created', $product, $product->only('name', 'variant', 'barcode', 'price'));
                    $created++;

                    return;
                }
                $changes = [];
                if ($price !== null && $price !== $product->price) {
                    $changes['price'] = [$product->price, $price];
                }
                // Erzap can hide a product; showing it again stays a website decision.
                if (array_key_exists('is_active', $item) && ! filter_var($item['is_active'], FILTER_VALIDATE_BOOL) && $product->is_active) {
                    $changes['is_active'] = [true, false];
                }
                if ($changes) {
                    $product->forceFill(array_map(fn ($change) => $change[1], $changes))->save();
                    Audit::record('catalog.erzap_api_updated', $product, $changes);
                    $updated++;
                }
            }, 3);
        }

        return [$created, $updated, $notes];
    }

    /** sinkronisasi_harga: updates selling prices of known products. Returns the number changed. */
    public function prices(array $items): int
    {
        $changed = 0;
        foreach ($items as $item) {
            $barcode = $this->barcode($item);
            $price = $this->price($item);
            if ($barcode === null || $price === null) {
                continue;
            }
            $product = Product::where('barcode', $barcode)->first();
            if ($product && $product->price !== $price) {
                Audit::record('catalog.erzap_api_price', $product, ['price' => [$product->price, $price]]);
                $product->forceFill(['price' => $price])->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * sinkronisasi_gambar: the cover image (or the first) becomes the product photo, only for products without one,
     * so photos uploaded by staff are never replaced. Returns the number of photos stored.
     */
    public function images(array $items, callable $file): int
    {
        $stored = 0;
        foreach ($items as $item) {
            $barcode = $this->barcode($item);
            $images = is_array($item['images_produk'] ?? null) ? $item['images_produk'] : [];
            $product = $barcode ? Product::where('barcode', $barcode)->first() : null;
            if (! $product || filled($product->image) || ! $images) {
                continue;
            }
            usort($images, fn ($a, $b) => (int) filter_var($b['is_cover'] ?? false, FILTER_VALIDATE_BOOL) <=> (int) filter_var($a['is_cover'] ?? false, FILTER_VALIDATE_BOOL));
            foreach ($images as $image) {
                $upload = $file('file_'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($image['ref'] ?? '')));
                if ($upload instanceof UploadedFile && $this->isPhoto($upload)) {
                    $product->forceFill(['image' => ContentImage::resolve($upload, null)])->save();
                    Audit::record('catalog.erzap_api_image', $product, ['image' => $product->image]);
                    $stored++;
                    break;
                }
            }
        }

        return $stored;
    }

    /**
     * check_pesanan: paid orders not yet saved in Erzap, oldest first. Orders with a product without barcode are left
     * out (Erzap could not match them); they show as "Needs mapping" in the dashboard.
     */
    public function pendingOrders(): array
    {
        $ids = IntegrationSync::where('provider', 'erzap')->where('type', ErzapSync::TYPE)->where('subject_type', 'orders')
            ->whereIn('status', IntegrationSync::RUNNABLE)->pluck('subject_id');

        return Order::with('items.product', 'outlet', 'customer', 'payments')->whereIn('id', $ids)->where('payment_status', 'paid')
            ->orderBy('created_at')->get()
            ->reject(fn (Order $order) => $order->items->contains(fn ($item) => blank($item->product?->barcode)))
            ->take(self::PULL_LIMIT)
            ->map(fn (Order $order) => $this->sync->pullCart($order))->values()->all();
    }

    /** update_status_pesanan with status 5: the order is saved in Erzap. False when the order code is unknown. */
    public function markSaved(string $code): bool
    {
        $order = Order::where('order_code', $code)->first();
        if (! $order) {
            return false;
        }
        ErzapSync::queue($order);
        IntegrationSync::where('provider', 'erzap')->where('type', ErzapSync::TYPE)->where('subject_type', 'orders')->where('subject_id', $order->id)
            ->where('status', '!=', 'synced')->update([
                'status' => 'synced', 'external_ref' => $order->order_code, 'last_error' => null, 'next_attempt_at' => null, 'synced_at' => now(), 'updated_at' => now(),
            ]);
        Audit::log('erzap.saved_by_erzap', 'orders', $order->id, ['order' => $order->order_code]);

        return true;
    }

    private function barcode(array $item): ?string
    {
        return $this->text($item['barcode'] ?? null, 80);
    }

    /**
     * Website price: harga_jual_web, else harga_jual; a discount (harga_diskon_web, else harga_diskon) below it wins.
     * Mini box contents are 0 in Erzap and stay 0.
     */
    private function price(array $item): ?int
    {
        $amount = fn (string $key) => is_numeric($item[$key] ?? null) && (float) $item[$key] >= 0 ? (int) round((float) $item[$key]) : null;
        $normal = ($amount('harga_jual_web') ?: null) ?? $amount('harga_jual');
        $discount = ($amount('harga_diskon_web') ?: null) ?? ($amount('harga_diskon') ?: null);

        return $normal !== null && $discount !== null && $discount < $normal ? $discount : $normal;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function isPhoto(UploadedFile $file): bool
    {
        $info = @getimagesize($file->getRealPath());

        return $file->getSize() <= 8 * 1024 * 1024 && in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }
}
