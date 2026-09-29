<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id', 'variant', 'sku', 'name', 'description', 'image', 'price', 'is_active',
        'is_hamper', 'hamper_contents', 'sale_starts_on', 'sale_ends_on', 'erzap_product_id', 'erzap_variant_id', 'barcode',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'is_hamper' => 'boolean', 'price' => 'integer',
            'sale_starts_on' => 'date:Y-m-d', 'sale_ends_on' => 'date:Y-m-d',
        ];
    }

    /** Name with its variant, e.g. "Berry (Halfsize)". */
    public function label(): string
    {
        return $this->name.($this->variant ? ' ('.$this->variant.')' : '');
    }

    /** Hamper contents, one item per line. */
    public function hamperItems(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->hamper_contents))));
    }

    /** Whether today (WITA) falls inside the product's sale period; no dates means always on sale. */
    public function onSale(?string $today = null): bool
    {
        $today ??= now('Asia/Makassar')->toDateString();

        return (! $this->sale_starts_on || $this->sale_starts_on->toDateString() <= $today)
            && (! $this->sale_ends_on || $this->sale_ends_on->toDateString() >= $today);
    }

    public function scopeOnSale(Builder $query): void
    {
        $today = now('Asia/Makassar')->toDateString();
        $query->where(fn ($q) => $q->whereNull('sale_starts_on')->orWhere('sale_starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('sale_ends_on')->orWhere('sale_ends_on', '>=', $today));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function outletPrices(): HasMany
    {
        return $this->hasMany(OutletPrice::class);
    }

    public function priceAt(Outlet $outlet): ?int
    {
        return $this->outletPrices()->where('outlet_id', $outlet->id)->value('price') ?? $this->price;
    }
}
