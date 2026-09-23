<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A date without PO pickup/delivery (holiday, event, outlet closed). */
class ClosedDate extends Model
{
    protected $fillable = ['date', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
