<?php

namespace App\Actions\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff record a payment received outside DOKU (bank transfer, cash, QRIS at the outlet).
 * It goes through ApplyPaymentStatus like a DOKU SUCCESS, so the order becomes paid and is queued for Erzap.
 */
class RecordManualPayment
{
    public const METHODS = ['transfer' => 'Bank transfer', 'cash' => 'Cash', 'qris' => 'QRIS (outlet)', 'other' => 'Other'];

    public function __construct(private ApplyPaymentStatus $apply) {}

    public function handle(Order $order, string $method, ?string $note, int $actor): Payment
    {
        $payment = DB::transaction(function () use ($order, $method, $note, $actor) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if ($locked->order_status !== 'confirmed' || $locked->payment_status === 'paid' || $locked->payment_status === 'refunded') {
                throw ValidationException::withMessages(['payment' => 'Only confirmed, unpaid orders can be marked as paid.']);
            }
            if (Payment::where('open_order_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'A DOKU payment link is still open for this order. Wait for it or cancel it first.']);
            }
            $attempt = (int) Payment::where('order_id', $locked->id)->max('attempt') + 1;
            $payment = Payment::create([
                'order_id' => $locked->id, 'attempt' => $attempt, 'provider' => 'manual', 'provider_order_id' => $locked->order_code.'-P'.$attempt,
                'open_order_id' => $locked->id, 'amount' => $locked->total, 'status' => 'pending', 'reason' => $note, 'created_by' => $actor,
            ]);
            Audit::record('payment.manual_recorded', $payment, ['attempt' => $attempt, 'amount' => $payment->amount, 'method' => $method], $actor);

            return $payment;
        }, 3);

        $this->apply->handle([
            'order_id' => $payment->provider_order_id, 'transaction_status' => 'SUCCESS', 'gross_amount' => $payment->amount,
            'payment_type' => 'MANUAL-'.strtoupper($method), 'transaction_date' => now()->toIso8601String(),
        ], 'manual');

        return $payment->fresh();
    }
}
