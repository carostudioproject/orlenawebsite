<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Outlet extends Model
{
    protected $fillable = ['code', 'name', 'address', 'maps_url', 'image', 'is_active', 'accepts_preorder', 'is_delivery_hub', 'show_on_website'];

    // show_on_website is chosen under website content, not in the Outlet form.
    protected $attributes = ['is_active' => true, 'accepts_preorder' => true, 'is_delivery_hub' => false, 'show_on_website' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'accepts_preorder' => 'boolean', 'is_delivery_hub' => 'boolean', 'show_on_website' => 'boolean', 'position' => 'integer'];
    }

    /** The single outlet that ships delivery (Gojek/Grab) orders, if it is currently taking PO. */
    public static function deliveryHub(): ?self
    {
        return self::takingPreorders()->where('is_delivery_hub', true)->first();
    }

    /** Operating outlets that currently take pre-orders. */
    public function scopeTakingPreorders(Builder $query): void
    {
        $query->where('is_active', true)->where('accepts_preorder', true);
    }

    public function takesPreorders(): bool
    {
        return $this->is_active && $this->accepts_preorder;
    }
}
