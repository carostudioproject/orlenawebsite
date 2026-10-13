<?php

namespace App\Actions\Payments;

use App\Actions\Integrations\ErzapSync;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentGateways;
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
        Audit::record('payment.status_changed', $payment, ['from' => $previous, 'to' => $target, 'attempt' => $payment->attempt]);

        // The order is settled by the money received on all its attempts against its current total, not by one attempt:
        // an older link paid after the total went up does not settle the order on its own.
        $received = (int) Payment::where('order_id', $order->id)->where('status', 'paid')->sum('amount');
        if ($target === 'paid') {
            if ($received < $order->total) {
                Audit::record('payment.underpaid', $order, ['received' => $received, 'total' => $order->total, 'attempt' => $payment->attempt]);

                return ['underpaid', 'Diterima Rp '.number_format($received, 0, ',', '.').' dari total Rp '.number_format($order->total, 0, ',', '.')];
            }
            $order->update(['payment_status' => 'paid']);
            // Paid orders go to Erzap; sent later by the scheduler, never inside this transaction.
            ErzapSync::queue($order);
            $this->closeOtherLinks($order, $payment);
        } elseif ($target === 'refunded') {
            // A refund only reopens the order when the money still held no longer covers it.
            if ($received < $order->total) {
                $order->update(['payment_status' => 'refunded']);
            }
        } elseif ($order->payment_status !== 'paid' && (int) Payment::where('order_id', $order->id)->max('attempt') === $payment->attempt) {
            // A stale attempt never overwrites the newest link's status, and nothing overwrites "paid".
            $order->update(['payment_status' => $target]);
        }

        return ['applied', null];
    }

    /** Once the order is paid, other open links are closed so the customer cannot pay twice (at DOKU after commit). */
    private function closeOtherLinks(Order $order, Payment $paid): void
    {
        $open = Payment::where('order_id', $order->id)->whereKeyNot($paid->id)->whereIn('status', Payment::OPEN)->lockForUpdate()->get();
        foreach ($open as $link) {
            $link->update(['status' => 'cancelled', 'open_order_id' => null, 'last_error' => 'Closed: the order was paid with attempt '.$paid->attempt.'.']);
            Audit::record('payment.closed_after_paid', $link, ['paid_attempt' => $paid->attempt]);
            DB::afterCommit(fn () => app(PaymentGateways::class)->for($link)?->cancel($link));
        }
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
