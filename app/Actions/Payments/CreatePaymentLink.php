<?php

namespace App\Actions\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Doku\DokuException;
use App\Services\Payments\PaymentGateways;
use App\Support\Audit;
use App\Support\OrderHistory;
use App\Support\PreorderDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePaymentLink
{
    private const LIFETIME_MINUTES = 24 * 60;

    private const MINIMUM_MINUTES = 15;

    /** A creation request with no recorded outcome after this long is treated as failed. */
    private const STALE_CREATING_MINUTES = 2;

    public function __construct(private PaymentGateways $gateways, private PreorderDate $dates) {}

    /** Confirms the order and, when DOKU is on, creates its payment link. Returns null when DOKU is off. */
    public function confirm(Order $order, int $reviewVersion, int $actor): ?Payment
    {
        $guard = function (Order $locked) use ($reviewVersion, $actor) {
            if ($locked->order_status !== 'pending_review' || $locked->payment_status !== 'not_created') {
                $this->fail('This order is already confirmed or no longer awaiting review.');
            }
            if ((int) $locked->review_version !== $reviewVersion) {
                $this->fail('The order was updated by the team. Reload the page and check the changes before confirming.');
            }
            if ($locked->delivery_fee === null) {
                $this->fail('Tentukan ongkir terlebih dahulu melalui form pemeriksaan.');
            }
            $locked->update(['order_status' => 'confirmed']);
            OrderHistory::record($locked, 'pending_review', 'confirmed', $actor);
            Audit::record('order.confirmed', $locked, ['order_status' => 'confirmed', 'total' => $locked->total], $actor);
        };
        if (! PaymentGateways::enabled()) {
            DB::transaction(fn () => $guard(Order::lockForUpdate()->findOrFail($order->id)), 3);

            return null;
        }

        return $this->open($order, $actor, null, $guard);
    }

    public function retry(Order $order, int $actor): Payment
    {
        return $this->open($order, $actor, null, function (Order $locked) {
            if ($locked->order_status !== 'confirmed' || $locked->payment_status !== 'not_created') {
                $this->fail('Retrying is only available for confirmed orders without a payment link.');
            }
        });
    }

    public function renew(Order $order, string $reason, int $actor): Payment
    {
        return $this->open($order, $actor, $reason, function (Order $locked) {
            if ($locked->order_status !== 'confirmed' || ! in_array($locked->payment_status, ['failed', 'expired', 'cancelled'], true)) {
                $this->fail('A new link can only be created after the previous one failed, expired or was cancelled.');
            }
        });
    }

    private function open(Order $order, int $actor, ?string $reason, callable $guard): Payment
    {
        $payment = DB::transaction(function () use ($order, $actor, $reason, $guard) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            Payment::where('open_order_id', $locked->id)->where('status', 'creating')
                ->where('updated_at', '<', now()->subMinutes(self::STALE_CREATING_MINUTES))
                ->update(['status' => 'creation_failed', 'open_order_id' => null, 'last_error' => 'No response recorded from DOKU']);
            if (Payment::where('open_order_id', $locked->id)->exists()) {
                $this->fail('A payment link is being created or is still open. Wait a moment, then reload the page.');
            }
            $expiresAt = $this->expiry($locked);
            $guard($locked);
            if ($locked->total < 1) {
                $this->fail('The order total must be more than Rp 0.');
            }
            $attempt = (int) Payment::where('order_id', $locked->id)->max('attempt') + 1;
            $payment = Payment::create([
                'order_id' => $locked->id, 'attempt' => $attempt, 'provider' => $this->gateways->current()->name(), 'provider_order_id' => $locked->order_code.'-P'.$attempt, 'provider_request_id' => (string) Str::uuid(),
                'open_order_id' => $locked->id, 'amount' => $locked->total, 'status' => 'creating',
                'expires_at' => $expiresAt, 'reason' => $reason, 'created_by' => $actor,
            ]);
            Audit::record('payment.requested', $payment, ['attempt' => $attempt, 'amount' => $payment->amount], $actor);

            return $payment;
        }, 3);

        return $this->request($payment, $actor);
    }

    private function request(Payment $payment, int $actor): Payment
    {
        $gateway = $this->gateways->for($payment);
        try {
            $checkout = $gateway->create($payment);
        } catch (DokuException $e) {
            DB::transaction(function () use ($payment, $actor, $e) {
                $locked = Payment::lockForUpdate()->findOrFail($payment->id);
                if ($locked->status === 'creating') {
                    $locked->update(['status' => 'creation_failed', 'open_order_id' => null, 'last_error' => mb_substr($e->getMessage(), 0, 255)]);
                    Audit::record('payment.creation_failed', $locked, ['attempt' => $locked->attempt], $actor);
                }
            });

            return $payment->fresh();
        }

        $stored = DB::transaction(function () use ($payment, $checkout, $actor) {
            $order = Order::lockForUpdate()->findOrFail($payment->order_id);
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            // The order may have been cancelled while DOKU was responding.
            if ($locked->status !== 'creating') {
                return false;
            }
            $locked->update([
                'status' => 'pending', 'checkout_token' => mb_substr((string) ($checkout['token'] ?? ''), 0, 100), 'payment_url' => $checkout['url'], 'qr_content' => $checkout['qr'] ?? null,
                'provider_request_id' => $checkout['reference'] ?? $locked->provider_request_id,
                // The provider's session/reference, shown to staff for support lookups.
                'transaction_id' => is_string($checkout['session_id'] ?? null) ? mb_substr($checkout['session_id'], 0, 80) : null, 'last_error' => null,
            ]);
            $order->update(['payment_status' => 'pending']);
            Audit::record('payment.created', $locked, ['attempt' => $locked->attempt, 'expires_at' => $locked->expires_at?->toIso8601String()], $actor);

            return true;
        });
        if (! $stored) {
            $gateway->cancel($payment->fresh()->fill(['provider_request_id' => $checkout['reference'] ?? $payment->provider_request_id]));
        }

        return $payment->fresh();
    }

    private function expiry(Order $order): CarbonImmutable
    {
        $now = CarbonImmutable::now('Asia/Makassar')->startOfMinute();
        $expiresAt = $now->addMinutes(self::LIFETIME_MINUTES)->min($this->dates->cutoff($order->requested_date->toDateString()));
        if ($now->diffInMinutes($expiresAt, false) < self::MINIMUM_MINUTES) {
            $this->fail('The payment deadline for this PO date has passed ('.$this->dates->cutoffLabel(english: true).'). Change the schedule in the review form or cancel the order.');
        }

        return $expiresAt;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
