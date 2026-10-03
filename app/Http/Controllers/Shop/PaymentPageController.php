<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Integrations\ErzapSync;
use App\Actions\Payments\ApplyPaymentStatus;
use App\Actions\Payments\ReconcilePayment;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Doku\DokuException;
use App\Services\Payments\PaymentGateways;
use App\Support\ShopPage;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Orlena's own payment page: the order summary and a QRIS to scan. Reached with the Order Code from the
 * staff's WhatsApp message or from Cek Pesanan; it shows no name, phone number or address.
 */
class PaymentPageController extends Controller
{
    public function show(Request $request, string $code, SiteContent $content)
    {
        $order = Order::with('items')->where('order_code', $code)->firstOrFail();
        $number = $content->get('social')['whatsappNumber'] ?? '';

        return ShopPage::render($request, 'Payment', 'Pembayaran', [
            'order' => [
                ...$order->only('order_code', 'order_status', 'payment_status', 'fulfillment_method', 'outlet_name_snapshot', 'requested_time', 'subtotal', 'delivery_fee', 'total'),
                'requested_date' => $order->requested_date->toDateString(),
                'items' => $order->items->map(fn ($item) => $item->only('id', 'product_name_snapshot', 'category_snapshot', 'variant_snapshot', 'quantity', 'subtotal'))->values(),
            ],
            'payment' => $this->payment($order),
            'whatsappUrl' => preg_match('/^[1-9][0-9]{7,14}$/', $number)
                ? 'https://wa.me/'.$number.'?text='.rawurlencode('Halo Orlena, saya ingin menanyakan pembayaran pesanan '.$order->order_code.'.') : null,
        ]);
    }

    /** Polled by the page; asks DOKU at most every 5 seconds per payment. */
    public function status(string $code, ReconcilePayment $reconcile)
    {
        $order = Order::where('order_code', $code)->firstOrFail();
        $open = Payment::where('order_id', $order->id)->where('status', 'pending')->where('provider', 'doku_qris')->latest('attempt')->first();
        if ($open && Cache::add('qris-check:'.$open->id, true, 5)) {
            try {
                $reconcile->handle($open);
            } catch (DokuException) {
                // Temporary DOKU errors: the page keeps polling and the scheduler checks again.
            }
        }
        $order->refresh();

        return response()->json(['payment_status' => $order->payment_status, 'order_status' => $order->order_status, 'payment' => $this->payment($order)])
            ->header('Cache-Control', 'no-store');
    }

    /** Demo mode only: marks the sample QRIS as paid, exactly as a DOKU success would, and sends the order to Erzap. */
    public function simulate(string $code, ApplyPaymentStatus $apply, ErzapSync $erzap)
    {
        abort_unless(PaymentGateways::demo(), 404);
        $order = Order::where('order_code', $code)->firstOrFail();
        $payment = Payment::where('order_id', $order->id)->where('provider', 'demo')->where('status', 'pending')->latest('attempt')->firstOrFail();
        $apply->handle([
            'order_id' => $payment->provider_order_id, 'transaction_status' => 'SUCCESS', 'gross_amount' => $payment->amount,
            'payment_type' => 'QRIS-DEMO', 'transaction_date' => now()->toIso8601String(),
        ], 'demo');
        $erzap->sendNow($order);

        return redirect('/bayar/'.$order->order_code);
    }

    /** The latest QRIS while it can still be paid. */
    private function payment(Order $order): ?array
    {
        $payment = Payment::where('order_id', $order->id)->latest('attempt')->first();
        if (! $payment || $payment->status !== 'pending' || ! $payment->qr_content || $payment->expires_at?->isPast()) {
            return null;
        }

        return ['qr' => $payment->qr_content, 'amount' => $payment->amount, 'expires_at' => $payment->expires_at?->toIso8601String(), 'demo' => $payment->provider === 'demo'];
    }
}
