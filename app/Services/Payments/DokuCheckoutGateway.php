<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Doku\DokuClient;

/** DOKU's hosted Checkout page (DOKU_MODE=checkout). */
class DokuCheckoutGateway implements PaymentGateway
{
    public function __construct(private DokuClient $doku) {}

    public function name(): string
    {
        return 'doku';
    }

    public function create(Payment $payment): array
    {
        $checkout = $this->doku->createCheckout($this->payload($payment), $payment->provider_request_id);

        return ['url' => $checkout['url'], 'token' => $checkout['token'], 'session_id' => $checkout['session_id']];
    }

    public function status(Payment $payment): ?array
    {
        return $this->doku->status($payment->provider_order_id);
    }

    public function cancel(Payment $payment): bool
    {
        return $payment->checkout_token !== null && $payment->provider_request_id !== null
            && $this->doku->cancel($payment->provider_order_id, $payment->provider_request_id);
    }

    private function payload(Payment $payment): array
    {
        $order = $payment->order()->with('items', 'customer')->firstOrFail();
        $items = $order->items->map(fn ($item) => [
            'id' => mb_substr($item->sku_snapshot, 0, 64), 'sku' => mb_substr($item->sku_snapshot, 0, 64), 'price' => $item->unit_price_snapshot, 'quantity' => $item->quantity,
            'name' => mb_substr($item->product_name_snapshot.' - '.($item->variant_snapshot ?? $item->category_snapshot), 0, 255),
        ]);
        if ($order->delivery_fee > 0) {
            $items->push(['id' => 'DELIVERY', 'sku' => 'DELIVERY', 'price' => $order->delivery_fee, 'quantity' => 1, 'name' => 'Ongkir']);
        }
        $orderData = [
            'amount' => $payment->amount, 'invoice_number' => $payment->provider_order_id, 'currency' => 'IDR', 'language' => 'ID',
            // "Back to merchant" on the DOKU page; the result page stays on DOKU.
            'callback_url' => url('/'), 'auto_redirect' => false,
        ];
        // DOKU requires line items to add up to the amount; the amount stays authoritative.
        if ($items->sum(fn ($item) => $item['price'] * $item['quantity']) === $payment->amount) {
            $orderData['line_items'] = $items->values()->all();
        }

        return [
            'order' => $orderData,
            'payment' => ['payment_due_date' => max(1, (int) now()->startOfMinute()->diffInMinutes($payment->expires_at))],
            'customer' => array_filter([
                'id' => 'CUST-'.$order->customer->id, 'name' => mb_substr($order->customer->name, 0, 255),
                'phone' => mb_substr($order->customer->whatsapp, 0, 16), 'email' => $order->customer->email,
            ]),
        ];
    }
}
