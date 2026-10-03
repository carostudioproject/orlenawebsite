<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Services\Payments\PaymentGateways;

/**
 * Reads the authoritative status from the gateway that created the payment.
 * Links that expire without payment may never produce a notification, so those are expired locally.
 */
class ReconcilePayment
{
    public function __construct(private PaymentGateways $gateways, private ApplyPaymentStatus $apply) {}

    public function handle(Payment $payment): string
    {
        $gateway = $this->gateways->for($payment);
        if (! $gateway) {
            return 'no_change';
        }
        $status = $gateway->status($payment);
        if ($status !== null && $status['transaction_status'] !== 'PENDING') {
            return $this->apply->handle($status, 'status_check');
        }
        if ($payment->status === 'pending' && $payment->expires_at?->isPast()) {
            return $this->apply->handle([
                'order_id' => $payment->provider_order_id, 'transaction_status' => 'EXPIRED', 'gross_amount' => (string) $payment->amount, 'transaction_date' => 'local',
            ], 'local_expiry');
        }

        return 'not_started';
    }
}
