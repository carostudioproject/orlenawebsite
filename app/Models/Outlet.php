<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Outlet extends Model
{
    protected $fillable = ['code', 'name', 'address', 'maps_url', 'image', 'is_active', 'accepts_preorder', 'delivers', 'daily_quota', 'hourly_quota', 'first_slot', 'closes_at', 'show_on_website'];

    // show_on_website is chosen under website content, not in the Outlet form.
    protected $attributes = ['is_active' => true, 'accepts_preorder' => true, 'delivers' => false, 'daily_quota' => 100, 'hourly_quota' => 20, 'first_slot' => '13:00:00', 'closes_at' => '21:00:00', 'show_on_website' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'accepts_preorder' => 'boolean', 'delivers' => 'boolean', 'show_on_website' => 'boolean', 'position' => 'integer',
            'daily_quota' => 'integer', 'hourly_quota' => 'integer'];
    }

    /** Outlets that ship delivery (Gojek/Grab) orders and currently take PO. The first one ships "nearest outlet" orders until staff choose. */
    public function scopeDelivering(Builder $query): void
    {
        $query->takingPreorders()->where('delivers', true)->orderBy('position')->orderBy('id');
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
