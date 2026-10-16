<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** Products whose contents the customer chooses: hampers (one item, its price added) or box (N items, priced per box). */
    public const CONTENT_TYPES = ['hampers' => 'Hampers', 'box' => 'Box'];

    /**
     * Every product type, as written in the Excel Tipe column. "set" is a box with fixed flavours (no choice);
     * "flavour" is only sold inside a box (Rp 0, never its own card). Regular products have no type.
     */
    public const TYPES = ['hampers' => 'Hampers', 'box' => 'Box', 'set' => 'Box isi tetap', 'flavour' => 'Rasa box'];

    /** added: each content costs its normal price on top; included: contents are Rp 0 (the box price covers them). */
    public const CONTENT_PRICING = ['added' => 'Ditambah', 'included' => 'Termasuk'];

    protected $fillable = [
        'category_id', 'variant', 'sku', 'name', 'description', 'image', 'price', 'is_active', 'is_featured', 'featured_label', 'featured_position',
        'input_label', 'input_placeholder', 'input_required', 'input_max',
        'is_hamper', 'hamper_contents', 'content_type', 'content_count', 'content_pricing', 'sale_starts_on', 'sale_ends_on', 'erzap_product_id', 'erzap_variant_id', 'barcode',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'is_featured' => 'boolean', 'featured_position' => 'integer', 'input_required' => 'boolean', 'input_max' => 'integer', 'is_hamper' => 'boolean', 'price' => 'integer', 'content_count' => 'integer',
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

    /** Whether the customer chooses this product's contents. */
    public function hasChoice(): bool
    {
        return isset(self::CONTENT_TYPES[$this->content_type]) && $this->content_count > 0;
    }

    /** A box whose flavours are fixed by Orlena; its contents come with every unit. */
    public function hasFixedContents(): bool
    {
        return $this->content_type === 'set';
    }

    /** Products allowed as contents ("Isi Dari"), or the fixed flavours of a set with how many of each. */
    public function contentOptions(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_contents', 'product_id', 'content_product_id')->withPivot('quantity');
    }

    /** Hampers and boxes that allow this product as contents. */
    public function containers(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_contents', 'content_product_id', 'product_id');
    }

    /**
     * Whether this product can be chosen as contents of $parent right now. Box contents (Rp 0) may be hidden from the
     * menu, e.g. Mini Size flavours; contents with their own price must be orderable on their own.
     */
    public function availableAsContentOf(Product $parent): bool
    {
        if ($parent->content_pricing === 'included' || $parent->hasFixedContents()) {
            return true;
        }

        return $this->is_active && $this->price > 0 && (bool) $this->category?->is_active && $this->onSale();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function outletPrices(): HasMany
    {
        return $this->hasMany(OutletPrice::class);
    }

    /** A greeting card sold as a product (e.g. "Card"): the order form then asks for the card message, as for hampers. */
    /** The text this product asks for on its cart line (greeting card, writing on a cake…), or null. */
    public function customerInput(): ?array
    {
        return filled($this->input_label) ? [
            'label' => $this->input_label, 'placeholder' => $this->input_placeholder, 'required' => $this->input_required, 'max' => $this->input_max ?: 150,
        ] : null;
    }

    public function priceAt(Outlet $outlet): ?int
    {
        return $this->outletPrices()->where('outlet_id', $outlet->id)->value('price') ?? $this->price;
    }
}
