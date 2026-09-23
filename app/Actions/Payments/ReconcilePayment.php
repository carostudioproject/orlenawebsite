<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Services\Midtrans\MidtransClient;

/**
 * Reads the authoritative status from Midtrans. Snap links that expire before the customer
 * picks a payment method never produce a webhook, so those are expired locally.
 */
class ReconcilePayment
{
    public function __construct(private MidtransClient $midtrans, private ApplyPaymentStatus $apply) {}

    public function handle(Payment $payment): string
    {
        $status = $this->midtrans->status($payment->provider_order_id);
        if ($status !== null) {
            return $this->apply->handle($status, 'status_check');
        }
        if ($payment->status === 'pending' && $payment->expires_at?->isPast()) {
            return $this->apply->handle([
                'order_id' => $payment->provider_order_id, 'transaction_status' => 'expire', 'status_code' => 'local',
                'gross_amount' => $payment->amount.'.00',
            ], 'local_expiry');
        }

        return 'not_started';
    }
}
