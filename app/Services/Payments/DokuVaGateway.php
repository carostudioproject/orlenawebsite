<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Doku\DokuVaClient;

/** BCA Virtual Account from the DOKU VA Direct API (SNAP); the number is shown on /bayar/{order code}. */
class DokuVaGateway implements PaymentGateway
{
    public function __construct(private DokuVaClient $va) {}

    public function name(): string
    {
        return 'doku_va';
    }

    public function create(Payment $payment): array
    {
        $va = $this->va->create($payment->provider_order_id, $payment->amount, (string) $payment->order->customer?->name, $payment->expires_at);

        return ['url' => url('/bayar/'.$payment->order->order_code), 'va' => $va['va'], 'session_id' => trim($va['va'])];
    }

    public function status(Payment $payment): ?array
    {
        return $payment->va_number ? $this->va->status($payment->va_number, $payment->provider_order_id) : null;
    }

    public function cancel(Payment $payment): bool
    {
        return ! $payment->va_number || $this->va->delete($payment->va_number, $payment->provider_order_id);
    }
}
