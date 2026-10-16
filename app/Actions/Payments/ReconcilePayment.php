<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Services\Payments\PaymentGateways;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the authoritative status from the gateway that created the payment.
 * Links that expire without payment may never produce a notification, so those are expired locally.
 */
class ReconcilePayment
{
    /** Seconds between two status checks of one payment triggered from outside (page polling, unsigned notifications). */
    public const COOLDOWN = 5;

    public function __construct(private PaymentGateways $gateways, private ApplyPaymentStatus $apply) {}

    /**
     * Checks the payment at most once per COOLDOWN seconds: callbacks anyone can send, and the payment page polling,
     * cannot make the site call DOKU over and over. Returns "throttled" when a check ran moments ago.
     */
    public function throttled(Payment $payment): string
    {
        return Cache::add('payment-check:'.$payment->id, true, self::COOLDOWN) ? $this->handle($payment) : 'throttled';
    }

    public function handle(Payment $payment): string
    {
        $gateway = $this->gateways->for($payment);
        if (! $gateway) {
            return 'no_change';
        }
        // Recorded before asking, so a payment DOKU keeps failing on is not retried every run (updated_at is left alone).
        Payment::whereKey($payment->id)->toBase()->update(['checked_at' => now()]);
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
