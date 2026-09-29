<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\ShopPage;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Order tracking with the Order Code only. It shows the status, items and schedule of that one order,
 * never the customer's name, WhatsApp, email or address, and it does not unlock adding items.
 */
class OrderStatusController extends Controller
{
    private const SESSION_KEY = 'tracked_orders';

    public function find(Request $request)
    {
        return ShopPage::render($request, 'TrackOrder', 'Cek pesanan', ['code' => $request->string('code')->limit(40, '')->upper()->value()]);
    }

    public function lookup(Request $request)
    {
        $request->merge(['order_code' => strtoupper(trim((string) $request->input('order_code')))]);
        $data = $request->validate(['order_code' => ['required', 'string', 'max:40']], ['order_code.required' => 'Masukkan Order Code.']);
        // Guessing codes is slowed per device.
        $key = 'order-track:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['order_code' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        $order = Order::where('order_code', $data['order_code'])->first(['id', 'order_code']);
        if (! $order) {
            RateLimiter::hit($key, 600);
            throw ValidationException::withMessages(['order_code' => 'Order Code tidak ditemukan. Periksa kembali huruf dan angkanya.']);
        }
        $tracked = $request->session()->get(self::SESSION_KEY, []);
        $request->session()->put(self::SESSION_KEY, array_slice(array_values(array_unique([...$tracked, $order->id])), -10));

        return redirect('/cek-pesanan/'.$order->order_code);
    }

    public function show(Request $request, string $code, SiteContent $content)
    {
        $order = Order::with('items', 'payments')->where('order_code', $code)->first();
        // The URL alone shows nothing: the code must have been entered in this browser session.
        if (! $order || ! in_array($order->id, $request->session()->get(self::SESSION_KEY, []), true)) {
            return redirect('/cek-pesanan?code='.rawurlencode($code));
        }
        $payment = $order->payments->sortByDesc('attempt')->first();
        $number = $content->get('social')['whatsappNumber'] ?? '';

        return ShopPage::render($request, 'OrderStatus', 'Status pesanan', [
            'order' => [
                ...$order->only('order_code', 'order_status', 'payment_status', 'fulfillment_method', 'outlet_name_snapshot', 'requested_time', 'subtotal', 'delivery_fee', 'total'),
                'requested_date' => $order->requested_date->toDateString(),
                'created_at' => $order->created_at->toIso8601String(),
                'items' => $order->items->map(fn ($item) => $item->only('id', 'product_name_snapshot', 'category_snapshot', 'variant_snapshot', 'quantity', 'subtotal'))->values(),
            ],
            // The DOKU link is only offered while it can still be paid.
            'payment' => $payment && $payment->status === 'pending' && $payment->payment_url && ! $payment->expires_at?->isPast()
                ? ['url' => $payment->payment_url, 'expires_at' => $payment->expires_at?->toIso8601String()] : null,
            'whatsappUrl' => preg_match('/^[1-9][0-9]{7,14}$/', $number)
                ? 'https://wa.me/'.$number.'?text='.rawurlencode('Halo Orlena, saya ingin menanyakan pesanan '.$order->order_code.'.') : null,
        ]);
    }
}
