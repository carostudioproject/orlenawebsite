<?php

namespace App\Actions\Payments;

use App\Actions\Integrations\ErzapSync;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Applies a verified Midtrans status (webhook, Get Status API, or local expiry) exactly once.
 */
class ApplyPaymentStatus
{
    public function handle(array $data, string $source): string
    {
        $providerOrderId = mb_substr((string) ($data['order_id'] ?? ''), 0, 50);
        $transactionStatus = mb_substr((string) ($data['transaction_status'] ?? ''), 0, 30);
        $fraudStatus = isset($data['fraud_status']) ? mb_substr((string) $data['fraud_status'], 0, 20) : null;
        $grossAmount = isset($data['gross_amount']) ? mb_substr((string) $data['gross_amount'], 0, 30) : null;
        $eventKey = hash('sha256', implode('|', [$providerOrderId, $data['transaction_id'] ?? '', $transactionStatus, $fraudStatus ?? '', $data['status_code'] ?? '', $grossAmount ?? '']));

        return DB::transaction(function () use ($data, $source, $providerOrderId, $transactionStatus, $fraudStatus, $grossAmount, $eventKey) {
            $found = Payment::where('provider_order_id', $providerOrderId)->first();
            // Lock order before payment, matching every other payment writer.
            $order = $found ? Order::lockForUpdate()->find($found->order_id) : null;
            $payment = $found ? Payment::lockForUpdate()->find($found->id) : null;

            $claimed = DB::table('payment_events')->insertOrIgnore([
                'payment_id' => $payment?->id, 'event_key' => $eventKey, 'source' => $source, 'provider_order_id' => $providerOrderId,
                'transaction_status' => $transactionStatus, 'fraud_status' => $fraudStatus, 'gross_amount' => $grossAmount,
                'processing_status' => 'received', 'received_at' => now(),
            ]);
            if ($claimed === 0) {
                return 'duplicate';
            }
            [$result, $reason] = $payment ? $this->apply($order, $payment, $data, $transactionStatus, $fraudStatus, $grossAmount) : ['unknown_payment', null];
            DB::table('payment_events')->where('event_key', $eventKey)->update(['processing_status' => $result, 'failure_reason' => $reason]);

            return $result;
        }, 3);
    }

    private function apply(Order $order, Payment $payment, array $data, string $transactionStatus, ?string $fraudStatus, ?string $grossAmount): array
    {
        if (! preg_match('/^(\d+)(?:\.0{1,2})?$/', (string) $grossAmount, $amount) || (int) $amount[1] !== $payment->amount) {
            Audit::record('payment.amount_mismatch', $payment, ['expected' => $payment->amount, 'received' => $grossAmount]);

            return ['amount_mismatch', 'Nominal Midtrans tidak sama dengan nominal pembayaran'];
        }
        $target = $this->target($transactionStatus, $fraudStatus);
        if ($target === null) {
            return ['needs_review', 'Status '.$transactionStatus.' perlu diperiksa manual'];
        }
        if ($target === $payment->status) {
            return ['no_change', null];
        }
        if (! $this->allowed($payment->status, $target)) {
            return ['ignored', 'Transisi '.$payment->status.' ke '.$target.' tidak diizinkan'];
        }

        $previous = $payment->status;
        $changes = ['status' => $target, 'open_order_id' => in_array($target, Payment::OPEN, true) ? $payment->open_order_id : null];
        if (isset($data['transaction_id'])) {
            $changes['transaction_id'] = mb_substr((string) $data['transaction_id'], 0, 80);
        }
        if (isset($data['payment_type'])) {
            $changes['payment_type'] = mb_substr((string) $data['payment_type'], 0, 40);
        }
        if ($target === 'paid') {
            $changes['paid_at'] = now();
        }
        $payment->update($changes);

        $latest = (int) Payment::where('order_id', $order->id)->max('attempt') === $payment->attempt;
        // A paid order only moves to refunded; a stale attempt never overwrites the newest link's status.
        if (in_array($target, ['paid', 'refunded'], true) || ($latest && $order->payment_status !== 'paid')) {
            $order->update(['payment_status' => $target]);
        }
        // Paid transactions go to Erzap; a refund voids them there. Sent later by the scheduler, never inside this transaction.
        if ($target === 'paid') {
            ErzapSync::queue($order, 'transaction.push');
        } elseif ($target === 'refunded') {
            ErzapSync::queue($order, 'transaction.cancel');
        }
        Audit::record('payment.status_changed', $payment, ['from' => $previous, 'to' => $target, 'attempt' => $payment->attempt]);

        return ['applied', null];
    }

    private function target(string $transactionStatus, ?string $fraudStatus): ?string
    {
        return match ($transactionStatus) {
            'settlement' => 'paid',
            'capture' => match ($fraudStatus) {
                null, 'accept' => 'paid',
                'challenge' => 'pending',
                default => 'failed',
            },
            'pending' => 'pending',
            'deny', 'failure' => 'failed',
            'cancel' => 'cancelled',
            'expire' => 'expired',
            'refund' => 'refunded',
            default => null,
        };
    }

    private function allowed(string $from, string $to): bool
    {
        return match ($to) {
            // Money received is always recorded, even after a link was cancelled or expired locally.
            'paid' => $from !== 'refunded',
            'refunded' => $from === 'paid',
            default => in_array($from, Payment::OPEN, true),
        };
    }
}
