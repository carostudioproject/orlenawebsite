<?php

namespace App\Models;

use App\Support\PreorderDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['owner_hash', 'request_hash', 'checkout_key'];

    protected function casts(): array
    {
        return ['requested_date' => 'date:Y-m-d', 'payment_due_at' => 'datetime', 'subtotal' => 'integer', 'delivery_fee' => 'integer', 'total' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Stores a priced line (OrderPricing::quote item) and its chosen contents as lines under it. */
    public function addLine(array $item): OrderItem
    {
        $snapshot = fn (array $line) => [
            'product_id' => $line['product_id'], 'product_name_snapshot' => $line['name'], 'category_snapshot' => $line['category'], 'variant_snapshot' => $line['variant'],
            'sku_snapshot' => $line['sku'], 'unit_price_snapshot' => $line['unit_price'], 'quantity' => $line['quantity'], 'subtotal' => $line['subtotal'],
        ];
        $parent = $this->items()->create($snapshot($item));
        foreach ($item['contents'] ?? [] as $content) {
            $this->items()->create([...$snapshot($content), 'parent_item_id' => $parent->id]);
        }

        return $parent;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function additions(): HasMany
    {
        return $this->hasMany(OrderAddition::class);
    }

    /**
     * Why the customer can no longer add items themselves, or null when they can: only while the order awaits review,
     * no payment link exists, and the requested date is still before its H-1 cutoff.
     */
    public function additionBlocker(): ?string
    {
        if ($this->order_status !== 'pending_review' || $this->payment_status !== 'not_created' || $this->payments()->whereIn('status', Payment::OPEN)->exists()) {
            return 'Pesanan sudah dikonfirmasi atau link pembayaran sudah dibuat. Hubungi admin Orlena melalui WhatsApp untuk mengubah pesanan.';
        }
        $dates = app(PreorderDate::class);
        if (now('Asia/Makassar')->greaterThan($dates->cutoff($this->requested_date->toDateString()))) {
            return 'Batas penambahan untuk tanggal PO ini sudah lewat ('.$dates->cutoffLabel().'). Hubungi admin Orlena melalui WhatsApp.';
        }

        return null;
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
