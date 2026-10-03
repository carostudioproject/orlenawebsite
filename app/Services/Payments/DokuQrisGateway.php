<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Doku\DokuQrisClient;

/** QRIS from the DOKU QRIS Direct API, paid on Orlena's own page /bayar/{order code} (DOKU_MODE=qris). */
class DokuQrisGateway implements PaymentGateway
{
    public function __construct(private DokuQrisClient $qris) {}

    public function name(): string
    {
        return 'doku_qris';
    }

    public function create(Payment $payment): array
    {
        $qris = $this->qris->generate($payment->provider_order_id, $payment->amount, $payment->expires_at);

        return ['url' => url('/bayar/'.$payment->order->order_code), 'qr' => $qris['qr'], 'reference' => $qris['reference'], 'session_id' => $qris['reference']];
    }

    public function status(Payment $payment): ?array
    {
        return $this->qris->query((string) $payment->provider_request_id, $payment->provider_order_id);
    }

    public function cancel(Payment $payment): bool
    {
        return $this->qris->cancel((string) $payment->provider_request_id, $payment->provider_order_id);
    }
}
