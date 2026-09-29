<?php

namespace App\Actions\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Doku\DokuClient;
use App\Services\Doku\DokuException;
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

    public function __construct(private DokuClient $doku, private PreorderDate $dates) {}

    public function confirm(Order $order, int $reviewVersion, int $actor): Payment
    {
        return $this->open($order, $actor, null, function (Order $locked) use ($reviewVersion, $actor) {
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
        });
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
                'order_id' => $locked->id, 'attempt' => $attempt, 'provider' => 'doku', 'provider_order_id' => $locked->order_code.'-P'.$attempt, 'provider_request_id' => (string) Str::uuid(),
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
        try {
            $checkout = $this->doku->createCheckout($this->payload($payment), $payment->provider_request_id);
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
                'status' => 'pending', 'checkout_token' => mb_substr($checkout['token'], 0, 100), 'payment_url' => $checkout['url'],
                // DOKU's checkout session id, shown to staff for support lookups.
                'transaction_id' => is_string($checkout['session_id']) ? mb_substr($checkout['session_id'], 0, 80) : null, 'last_error' => null,
            ]);
            $order->update(['payment_status' => 'pending']);
            Audit::record('payment.created', $locked, ['attempt' => $locked->attempt, 'expires_at' => $locked->expires_at?->toIso8601String()], $actor);

            return true;
        });
        if (! $stored) {
            $this->doku->cancel($payment->provider_order_id, $payment->provider_request_id);
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

    private function payload(Payment $payment): array
    {
        $order = $payment->order()->with('items', 'customer')->firstOrFail();
        $items = $order->items->map(fn ($item) => [
            'id' => mb_substr($item->sku_snapshot, 0, 64), 'sku' => mb_substr($item->sku_snapshot, 0, 64), 'price' => $item->unit_price_snapshot, 'quantity' => $item->quantity,
            'name' => mb_substr($item->product_name_snapshot.' - '.($item->variant_snapshot ?? $item->category_snapshot), 0, 255),
        ]);
        if ($order->delivery_fee > 0) {
            $items->push(['id' => 'DELIVERY', 'sku' => 'DELIVERY', 'price' => $order->delivery_fee, 'quantity' => 1, 'name' => 'Ongkir']);
        }
        $orderData = [
            'amount' => $payment->amount, 'invoice_number' => $payment->provider_order_id, 'currency' => 'IDR', 'language' => 'ID',
            // "Back to merchant" on the DOKU page; the result page stays on DOKU.
            'callback_url' => url('/'), 'auto_redirect' => false,
        ];
        // DOKU requires line items to add up to the amount; the amount stays authoritative.
        if ($items->sum(fn ($item) => $item['price'] * $item['quantity']) === $payment->amount) {
            $orderData['line_items'] = $items->values()->all();
        }

        return [
            'order' => $orderData,
            'payment' => ['payment_due_date' => max(1, (int) now()->startOfMinute()->diffInMinutes($payment->expires_at))],
            'customer' => array_filter([
                'id' => 'CUST-'.$order->customer->id, 'name' => mb_substr($order->customer->name, 0, 255),
                'phone' => mb_substr($order->customer->whatsapp, 0, 16), 'email' => $order->customer->email,
            ]),
        ];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }
}
