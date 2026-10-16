<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price_snapshot' => 'integer', 'quantity' => 'integer', 'subtotal' => 'integer'];
    }

    /** Chosen contents of a hampers/box line. */
    public function contents(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'parent_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
