<?php

namespace App\Actions\Payments;

use App\Actions\Integrations\ErzapSync;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Services\Ordering\Capacity;
use App\Services\Payments\PaymentGateways;
use App\Support\Audit;
use App\Support\PreorderDate;
use Carbon\CarbonImmutable;
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
        $paidAt = $this->paidAt($data);
        if ($target === 'paid') {
            $changes['paid_at'] = $paidAt;
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
            // Only the payment that settles the order decides its date; a later extra receipt never moves it.
            $settles = $order->payment_status !== 'paid';
            $order->update(['payment_status' => 'paid']);
            if ($settles) {
                $this->moveIfLate($order, $paidAt);
            }
            // Paid orders go to Erzap (with the final date); sent later by the scheduler, never inside this transaction.
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

    /**
     * Rules Order Website: paid after the H-1 cutoff, the order misses that day's production and moves to the next open date
     * (usually H+2), same time. Staff then tell the customer with the WhatsApp button on the order page.
     */
    private function moveIfLate(Order $order, CarbonImmutable $paidAt): void
    {
        $dates = app(PreorderDate::class);
        $from = $order->requested_date->toDateString();
        if (! in_array($order->order_status, ['pending_review', 'confirmed'], true) || $paidAt->lessThanOrEqualTo($dates->cutoff($from))) {
            return;
        }
        // The same outlet lock as checkout, so the moved order cannot take a place another order is taking right now.
        $order->setRelation('outlet', Outlet::lockForUpdate()->find($order->outlet_id));
        $to = app(Capacity::class)->nextOpenDate($order);
        $time = $order->requested_time ? ' '.substr($order->requested_time, 0, 5) : '';
        $order->update(['requested_date' => $to, 'moved_from_date' => $from, 'review_version' => $order->review_version + 1]);
        DB::table('order_reviews')->insert([
            'order_id' => $order->id, 'actor_id' => null, 'previous_delivery_fee' => $order->delivery_fee, 'delivery_fee' => $order->delivery_fee,
            'note' => 'Paid after the cut-off ('.$dates->cutoffLabel(english: true).'): moved to the next open date.',
            'previous_schedule' => $from.$time, 'schedule' => $to.$time, 'created_at' => now(),
        ]);
        Audit::record('order.moved_after_cutoff', $order, ['from' => $from, 'to' => $to]);
    }

    /**
     * When the money was received: the provider's verified time when it carries a time zone (a payment at 18:59 notified
     * at 19:01 still makes the cutoff), else now. A time in the future or over a day old is not trusted.
     */
    private function paidAt(array $data): CarbonImmutable
    {
        $now = CarbonImmutable::now('Asia/Makassar');
        $value = $data['transaction_date'] ?? null;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return $now;
        }
        try {
            $at = CarbonImmutable::parse($value)->setTimezone('Asia/Makassar');
        } catch (\Throwable) {
            return $now;
        }

        return $at->greaterThan($now) || $at->lessThan($now->subDay()) ? $now : $at;
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
