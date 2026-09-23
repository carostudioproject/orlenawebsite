<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * A browser may see or extend an order when it placed the order (session owner) or verified
 * Order Code + WhatsApp number in this session. The Order Code alone never grants access.
 */
class OrderAccess
{
    private const SESSION_KEY = 'verified_orders';

    public static function allows(Request $request, Order $order): bool
    {
        $owner = $request->session()->get('order_owner');
        if (is_string($owner) && hash_equals($order->owner_hash, hash('sha256', $owner))) {
            return true;
        }

        return in_array($order->id, $request->session()->get(self::SESSION_KEY, []), true);
    }

    public static function grant(Request $request, Order $order): void
    {
        $verified = $request->session()->get(self::SESSION_KEY, []);
        // Keep the list short; older verifications simply need to be repeated.
        $request->session()->put(self::SESSION_KEY, array_slice(array_values(array_unique([...$verified, $order->id])), -10));
    }
}
