<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'image', 'is_active', 'show_on_website', 'order_position'];

    /** Active categories can be used for products and orders; show_on_website is chosen under website content (Baked Goods). */
    protected $attributes = ['is_active' => true, 'show_on_website' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'show_on_website' => 'boolean', 'position' => 'integer', 'order_position' => 'integer'];
    }
}
