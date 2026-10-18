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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePaymentLink
{
    /** Rules Order Website: a payment link is valid for 2 hours, and never past H-1 payment close. */
    private const LIFETIME_MINUTES = 120;

    private const MINIMUM_MINUTES = 15;

    /** A creation request with no recorded outcome after this long is treated as failed. */
    private const STALE_CREATING_MINUTES = 2;

    /** Payment attempts per order; switching method on /bayar adds one each time. */
    private const MAX_ATTEMPTS = 12;

    public function __construct(private PaymentGateways $gateways, private PreorderDate $dates, private ReconcilePayment $reconcile) {}

    /**
     * Confirms the order and, when DOKU is on, creates its payment link. Returns null when no link is created:
     * DOKU is off, or the customer chooses the method on /bayar (the deadline is set now, the link when they choose).
     */
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
            // The outlet suggested for "nearest outlet" is only a placeholder; staff choose it in the review form.
            if ($locked->nearest_outlet) {
                $this->fail('Choose the shipping outlet in the review form first: the customer asked for the nearest outlet.');
            }
            $locked->update(['order_status' => 'confirmed']);
            OrderHistory::record($locked, 'pending_review', 'confirmed', $actor);
            Audit::record('order.confirmed', $locked, ['order_status' => 'confirmed', 'total' => $locked->total], $actor);
        };
        if (! PaymentGateways::enabled()) {
            DB::transaction(fn () => $guard(Order::lockForUpdate()->findOrFail($order->id)), 3);

            return null;
        }
        if (PaymentGateways::customerChooses()) {
            $this->openChoice($order, $guard);

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

    /** A new link after the previous one failed, expired or was cancelled; null when the customer chooses the method again on /bayar. */
    public function renew(Order $order, string $reason, int $actor): ?Payment
    {
        $guard = function (Order $locked) {
            $choiceExpired = $locked->payment_status === 'not_created' && $locked->payment_due_at?->isPast();
            if ($locked->order_status !== 'confirmed' || (! $choiceExpired && ! in_array($locked->payment_status, ['failed', 'expired', 'cancelled'], true))) {
                $this->fail('A new link can only be created after the previous one failed, expired or was cancelled.');
            }
        };
        if (PaymentGateways::customerChooses()) {
            $this->openChoice($order, function (Order $locked) use ($guard, $reason, $actor) {
                $guard($locked);
                Audit::record('payment.reopened', $locked, ['reason' => $reason], $actor);
            });

            return null;
        }

        return $this->open($order, $actor, $reason, $guard);
    }

    /** No link yet: the order waits for the customer to pick a method on /bayar until payment_due_at. */
    private function openChoice(Order $order, callable $guard): void
    {
        DB::transaction(function () use ($order, $guard) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if (Payment::where('open_order_id', $locked->id)->exists()) {
                $this->fail('A payment link is being created or is still open. Wait a moment, then reload the page.');
            }
            $dueAt = $this->expiry($locked);
            $guard($locked);
            $locked->update(['payment_status' => 'not_created', 'payment_due_at' => $dueAt]);
        }, 3);
    }

    /**
     * The customer picks how to pay on /bayar. An open link with another method is checked (it may just have been paid)
     * and closed at DOKU first; the new link keeps the old deadline. Without an open link (the previous creation failed),
     * a confirmed order gets its first link here.
     */
    public function choose(Order $order, string $method): Payment
    {
        if (! PaymentGateways::enabled() || ! in_array($method, PaymentGateways::methods(), true)) {
            $this->fail('Metode pembayaran ini tidak tersedia.');
        }
        $lock = Cache::lock('payment-choose:'.$order->id, 60);
        if (! $lock->get()) {
            $this->fail('Metode sebelumnya masih diproses. Coba lagi sebentar.');
        }
        try {
            return $this->switchTo($order, $method);
        } finally {
            $lock->release();
        }
    }

    private function switchTo(Order $order, string $method): Payment
    {
        $label = PaymentGateways::METHOD_LABELS[$method];
        $current = Payment::where('open_order_id', $order->id)->first();
        if ($current && $current->method === $method && $current->status === 'pending') {
            return $current;
        }
        if ((int) Payment::where('order_id', $order->id)->max('attempt') >= self::MAX_ATTEMPTS) {
            $this->fail('Metode pembayaran sudah terlalu sering diganti. Hubungi Orlena melalui WhatsApp.');
        }
        $expiresAt = $order->payment_due_at?->toImmutable();
        if ($current) {
            if ($current->status !== 'pending') {
                $this->fail('Pembayaran sedang disiapkan. Muat ulang halaman sebentar lagi.');
            }
            try {
                $this->reconcile->handle($current);
            } catch (DokuException) {
                $this->fail('Status pembayaran belum bisa diperiksa. Coba lagi sebentar.');
            }
            $current->refresh();
            if ($current->status !== 'pending') {
                $this->fail($current->status === 'paid' ? 'Pembayaran sudah diterima.' : 'Batas waktu pembayaran sudah lewat. Hubungi Orlena untuk kode baru.');
            }
            if (! $this->gateways->for($current)?->cancel($current)) {
                $this->fail('Metode sebelumnya belum bisa ditutup. Coba lagi sebentar.');
            }
            $expiresAt = $current->expires_at?->toImmutable();
            DB::transaction(function () use ($current, $label) {
                $order = Order::lockForUpdate()->findOrFail($current->order_id);
                $locked = Payment::lockForUpdate()->findOrFail($current->id);
                if ($locked->status !== 'pending') {
                    $this->fail('Status pembayaran baru saja berubah. Muat ulang halaman.');
                }
                $locked->update(['status' => 'cancelled', 'open_order_id' => null]);
                $order->update(['payment_status' => 'not_created']);
                Audit::record('payment.method_changed', $locked, ['attempt' => $locked->attempt, 'to' => $label]);
            }, 3);
        }

        return $this->open($order, null, 'Pelanggan memilih '.$label, function (Order $locked) {
            if ($locked->order_status !== 'confirmed' || $locked->payment_status !== 'not_created') {
                $this->fail('Pembayaran pesanan ini tidak bisa dibuat. Hubungi Orlena melalui WhatsApp.');
            }
        }, $method, $expiresAt);
    }

    private function open(Order $order, ?int $actor, ?string $reason, callable $guard, ?string $method = null, ?CarbonImmutable $deadline = null): Payment
    {
        $payment = DB::transaction(function () use ($order, $actor, $reason, $guard, $method, $deadline) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            Payment::where('open_order_id', $locked->id)->where('status', 'creating')
                ->where('updated_at', '<', now()->subMinutes(self::STALE_CREATING_MINUTES))
                ->update(['status' => 'creation_failed', 'open_order_id' => null, 'last_error' => 'No response recorded from DOKU']);
            if (Payment::where('open_order_id', $locked->id)->exists()) {
                $this->fail('A payment link is being created or is still open. Wait a moment, then reload the page.');
            }
            // A kept deadline still stops at the current date's H-1 payment close (the schedule may have changed).
            $deadline = $deadline?->min($this->dates->paymentClose($locked->requested_date->toDateString()));
            if ($deadline && ! $deadline->isFuture()) {
                $this->fail('Batas waktu pembayaran sudah lewat. Hubungi Orlena melalui WhatsApp untuk link baru.');
            }
            $expiresAt = $deadline ?? $this->expiry($locked);
            $guard($locked);
            if ($locked->total < 1) {
                $this->fail('The order total must be more than Rp 0.');
            }
            $attempt = (int) Payment::where('order_id', $locked->id)->max('attempt') + 1;
            $payment = Payment::create([
                'order_id' => $locked->id, 'attempt' => $attempt, 'provider' => $this->gateways->current($method)->name(), 'method' => PaymentGateways::method($method), 'provider_order_id' => $locked->order_code.'-P'.$attempt, 'provider_request_id' => (string) Str::uuid(),
                'open_order_id' => $locked->id, 'amount' => $locked->total, 'status' => 'creating',
                'expires_at' => $expiresAt, 'reason' => $reason, 'created_by' => $actor,
            ]);
            Audit::record('payment.requested', $payment, ['attempt' => $attempt, 'amount' => $payment->amount], $actor);

            return $payment;
        }, 3);

        return $this->request($payment, $actor);
    }

    private function request(Payment $payment, ?int $actor): Payment
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
                'status' => 'pending', 'checkout_token' => mb_substr((string) ($checkout['token'] ?? ''), 0, 100), 'payment_url' => $checkout['url'], 'qr_content' => $checkout['qr'] ?? null, 'va_number' => $checkout['va'] ?? null,
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
        $expiresAt = $now->addMinutes(self::LIFETIME_MINUTES)->min($this->dates->paymentClose($order->requested_date->toDateString()));
        if ($now->diffInMinutes($expiresAt, false) < self::MINIMUM_MINUTES) {
            $this->fail('The payment deadline for this PO date has passed ('.$this->dates->paymentCloseLabel(english: true).'). Change the schedule in the review form or cancel the order.');
        }

        return $expiresAt;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
