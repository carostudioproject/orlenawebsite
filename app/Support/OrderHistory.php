<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderHistory
{
    public static function record(Order $order, ?string $from, string $to, ?int $actor, ?string $note = null): void
    {
        DB::table('order_status_histories')->insert([
            'order_id' => $order->id, 'actor_id' => $actor, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => now(),
        ]);
    }
}
