<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Services\Doku\DokuClient;

/**
 * Reads the authoritative status from DOKU. Checkout links that expire before the customer
 * pays may never produce a notification, so those are expired locally.
 */
class ReconcilePayment
{
    public function __construct(private DokuClient $doku, private ApplyPaymentStatus $apply) {}

    public function handle(Payment $payment): string
    {
        $status = $this->doku->status($payment->provider_order_id);
        if ($status !== null && ! ($status['transaction_status'] === 'PENDING' && $payment->expires_at?->isPast())) {
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
