<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Integrations\ErzapSync;
use App\Actions\Payments\ApplyPaymentStatus;
use App\Actions\Payments\CreatePaymentLink;
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
 * Orlena's own payment page: the order summary and a BCA Virtual Account or QRIS, as the customer chooses. Reached with the Order Code from the
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
            'methods' => $this->methods($order),
            'dueAt' => $order->payment_due_at?->toIso8601String(),
            'whatsappUrl' => preg_match('/^[1-9][0-9]{7,14}$/', $number)
                ? 'https://wa.me/'.$number.'?text='.rawurlencode('Halo Orlena, saya ingin menanyakan pembayaran pesanan '.$order->order_code.'.') : null,
        ]);
    }

    /** Polled by the page; asks DOKU at most every 5 seconds per payment. */
    public function status(string $code, ReconcilePayment $reconcile)
    {
        $order = Order::where('order_code', $code)->firstOrFail();
        $open = Payment::where('order_id', $order->id)->where('status', 'pending')->whereIn('provider', ['doku_qris', 'doku_va'])->latest('attempt')->first();
        if ($open && Cache::add('qris-check:'.$open->id, true, 5)) {
            try {
                $reconcile->handle($open);
            } catch (DokuException) {
                // Temporary DOKU errors: the page keeps polling and the scheduler checks again.
            }
        }
        $order->refresh();

        return response()->json(['payment_status' => $order->payment_status, 'order_status' => $order->order_status, 'payment' => $this->payment($order), 'methods' => $this->methods($order)])
            ->header('Cache-Control', 'no-store');
    }

    /** The customer picks (or switches to) VA BCA or QRIS; see CreatePaymentLink::choose. */
    public function choose(Request $request, string $code, CreatePaymentLink $links)
    {
        $order = Order::where('order_code', $code)->firstOrFail();
        $data = $request->validate(['method' => ['required', 'string', 'in:'.implode(',', array_keys(PaymentGateways::METHOD_LABELS))]]);
        $payment = $links->choose($order, $data['method']);
        if ($payment->status !== 'pending') {
            return redirect('/bayar/'.$order->order_code)->withErrors(['method' => 'Kode pembayaran belum bisa dibuat. Coba lagi sebentar atau hubungi Orlena.']);
        }

        return redirect('/bayar/'.$order->order_code);
    }

    /** Demo mode only: marks the sample QRIS as paid, exactly as a DOKU success would, and sends the order to Erzap. */
    public function simulate(string $code, ApplyPaymentStatus $apply, ErzapSync $erzap)
    {
        abort_unless(PaymentGateways::demo(), 404);
        $order = Order::where('order_code', $code)->firstOrFail();
        $payment = Payment::where('order_id', $order->id)->where('provider', 'demo')->where('status', 'pending')->latest('attempt')->firstOrFail();
        $apply->handle([
            'order_id' => $payment->provider_order_id, 'transaction_status' => 'SUCCESS', 'gross_amount' => $payment->amount,
            'payment_type' => $payment->method === 'va_bca' ? 'VA-BCA-DEMO' : 'QRIS-DEMO', 'transaction_date' => now()->toIso8601String(),
        ], 'demo');
        $erzap->sendNow($order);

        return redirect('/bayar/'.$order->order_code);
    }

    /** The open payment: a VA number or QRIS to show here, or (DOKU Checkout) the DOKU page to continue on. */
    private function payment(Order $order): ?array
    {
        $payment = Payment::where('order_id', $order->id)->latest('attempt')->first();
        if (! $payment || $payment->status !== 'pending' || $payment->expires_at?->isPast() || (! $payment->qr_content && ! $payment->va_number && ! $payment->payment_url)) {
            return null;
        }

        return [
            'qr' => $payment->qr_content, 'amount' => $payment->amount, 'expires_at' => $payment->expires_at?->toIso8601String(), 'demo' => $payment->provider === 'demo',
            'method' => $payment->va_number ? 'va_bca' : ($payment->qr_content ? 'qris' : 'checkout'),
            'va' => $payment->va_number ? trim($payment->va_number) : null,
            'checkout_url' => $payment->qr_content || $payment->va_number ? null : $payment->payment_url,
        ];
    }

    /** Methods the customer may pick: while a link on /bayar is open, or when a confirmed order has none yet. */
    private function methods(Order $order): array
    {
        $methods = PaymentGateways::enabled() ? PaymentGateways::methods() : [];
        $open = Payment::where('open_order_id', $order->id)->first();
        $choosable = $order->order_status === 'confirmed' && ($open
            ? $open->status === 'pending' && ($open->qr_content || $open->va_number)
            : $order->payment_status === 'not_created' && ! $order->payment_due_at?->isPast());

        return $choosable ? array_map(fn ($method) => ['value' => $method, 'label' => PaymentGateways::METHOD_LABELS[$method]], $methods) : [];
    }
}
