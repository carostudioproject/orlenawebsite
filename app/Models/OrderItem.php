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
        return ['unit_price_snapshot' => 'integer', 'quantity' => 'integer', 'subtotal' => 'integer', 'input_texts' => 'array'];
    }

    /** "Tulisan kartu ucapan: …" per written text, numbered when there are several. */
    public static function formatInput(?string $label, ?array $texts): array
    {
        $texts = array_values($texts ?? []);

        return array_map(fn ($text, $unit) => ($label ?? 'Tulisan').(count($texts) > 1 ? ' '.($unit + 1) : '').': '.$text, $texts, array_keys($texts));
    }

    public function inputLines(): array
    {
        return self::formatInput($this->input_label_snapshot, $this->input_texts);
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
