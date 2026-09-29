<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** Statuses where the customer may still pay through this link. */
    public const OPEN = ['creating', 'pending'];

    protected $guarded = ['id'];

    protected $hidden = ['checkout_token', 'provider_request_id', 'open_order_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'attempt' => 'integer', 'expires_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
