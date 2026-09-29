<?php

namespace App\Actions\Payments;

use App\Actions\Integrations\ErzapSync;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Applies a verified DOKU status (HTTP notification, Check Status API, or local expiry) exactly once.
 * Input is DokuClient::normalize() output: order_id (invoice number), transaction_status, gross_amount, payment_type.
 */
class ApplyPaymentStatus
{
    public function handle(array $data, string $source): string
    {
        $providerOrderId = mb_substr((string) ($data['order_id'] ?? ''), 0, 50);
        $transactionStatus = strtoupper(mb_substr((string) ($data['transaction_status'] ?? ''), 0, 30));
        $grossAmount = isset($data['gross_amount']) ? mb_substr((string) $data['gross_amount'], 0, 30) : null;
        $eventKey = hash('sha256', implode('|', [$providerOrderId, $transactionStatus, $grossAmount ?? '', $data['payment_type'] ?? '', $data['transaction_date'] ?? '']));

        return DB::transaction(function () use ($data, $source, $providerOrderId, $transactionStatus, $grossAmount, $eventKey) {
            $found = Payment::where('provider_order_id', $providerOrderId)->first();
            // Lock order before payment, matching every other payment writer.
            $order = $found ? Order::lockForUpdate()->find($found->order_id) : null;
            $payment = $found ? Payment::lockForUpdate()->find($found->id) : null;

            $claimed = DB::table('payment_events')->insertOrIgnore([
                'payment_id' => $payment?->id, 'event_key' => $eventKey, 'source' => $source, 'provider_order_id' => $providerOrderId,
                'transaction_status' => $transactionStatus, 'gross_amount' => $grossAmount,
                'processing_status' => 'received', 'received_at' => now(),
            ]);
            if ($claimed === 0) {
                return 'duplicate';
            }
            [$result, $reason] = $payment ? $this->apply($order, $payment, $data, $transactionStatus, $grossAmount) : ['unknown_payment', null];
            DB::table('payment_events')->where('event_key', $eventKey)->update(['processing_status' => $result, 'failure_reason' => $reason]);

            return $result;
        }, 3);
    }

    private function apply(Order $order, Payment $payment, array $data, string $transactionStatus, ?string $grossAmount): array
    {
        if (! preg_match('/^(\d+)(?:\.0{1,2})?$/', (string) $grossAmount, $amount) || (int) $amount[1] !== $payment->amount) {
            Audit::record('payment.amount_mismatch', $payment, ['expected' => $payment->amount, 'received' => $grossAmount]);

            return ['amount_mismatch', 'Nominal DOKU tidak sama dengan nominal pembayaran'];
        }
        $target = $this->target($transactionStatus);
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
        // Paid orders go to Erzap; sent later by the scheduler, never inside this transaction.
        if ($target === 'paid') {
            ErzapSync::queue($order);
        }
        Audit::record('payment.status_changed', $payment, ['from' => $previous, 'to' => $target, 'attempt' => $payment->attempt]);

        return ['applied', null];
    }

    private function target(string $transactionStatus): ?string
    {
        return match ($transactionStatus) {
            'SUCCESS' => 'paid',
            // FAILED on DOKU Checkout means one attempt failed; the customer may still pay with another method on the same link.
            'PENDING', 'TIMEOUT', 'REDIRECT', 'FAILED' => 'pending',
            'EXPIRED' => 'expired',
            'CANCELLED' => 'cancelled',
            'REFUNDED' => 'refunded',
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
