<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutletPrice extends Model
{
    protected $fillable = ['outlet_id', 'price'];

    protected function casts(): array
    {
        return ['price' => 'integer'];
    }
}
