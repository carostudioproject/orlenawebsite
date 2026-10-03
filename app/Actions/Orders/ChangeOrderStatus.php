<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentGateways;
use App\Support\Audit;
use App\Support\OrderHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeOrderStatus
{
    private const NEXT = [
        'confirmed' => ['processing'],
        'processing' => ['ready'],
        'ready' => ['delivering', 'completed'],
        'delivering' => ['completed'],
    ];

    public function __construct(private PaymentGateways $gateways) {}

    public function advance(Order $order, string $from, string $to, User $actor): void
    {
        DB::transaction(function () use ($order, $from, $to, $actor) {
            $locked = $this->lock($order, $from);
            if (! in_array($to, self::NEXT[$from] ?? [], true)) {
                $this->fail('The status cannot change from '.$from.' to '.$to.'.');
            }
            if ($to === 'processing' && $locked->payment_status !== 'paid') {
                $this->fail('The order can only be processed once it is paid.');
            }
            if ($from === 'ready' && ($to === 'delivering') !== ($locked->fulfillment_method === 'delivery')) {
                $this->fail($to === 'delivering' ? 'Pickup orders do not go out for delivery.' : 'Delivery orders must go out for delivery first.');
            }
            $locked->update(['order_status' => $to]);
            OrderHistory::record($locked, $from, $to, $actor->id);
            Audit::record('order.status_changed', $locked, ['from' => $from, 'to' => $to], $actor->id);
        }, 3);
    }

    /** Returns false when an open DOKU link could not be closed and may still accept payment. */
    public function cancel(Order $order, string $from, string $reason, User $actor): bool
    {
        $closing = DB::transaction(function () use ($order, $from, $reason, $actor) {
            $locked = $this->lock($order, $from);
            if (in_array($from, ['completed', 'cancelled'], true)) {
                $this->fail('Completed or cancelled orders cannot be cancelled.');
            }
            if ($locked->payment_status === 'paid' && ! $actor->can('cancel-paid-orders')) {
                $this->fail('Paid orders can only be cancelled by an Admin because they need a refund.');
            }
            $open = Payment::where('order_id', $locked->id)->whereIn('status', Payment::OPEN)->lockForUpdate()->get();
            foreach ($open as $payment) {
                $payment->update(['status' => 'cancelled', 'open_order_id' => null]);
                Audit::record('payment.cancelled', $payment, ['attempt' => $payment->attempt], $actor->id);
            }
            $locked->update(['order_status' => 'cancelled'] + ($locked->payment_status === 'pending' ? ['payment_status' => 'cancelled'] : []));
            OrderHistory::record($locked, $from, 'cancelled', $actor->id, $reason);
            Audit::record('order.cancelled', $locked, ['from' => $from, 'payment_status' => $locked->payment_status], $actor->id);

            return $open;
        }, 3);

        // Each open link is closed by the gateway that created it; manual payments have none.
        return $closing->every(fn ($payment) => $this->gateways->for($payment)?->cancel($payment) ?? true);
    }

    private function lock(Order $order, string $from): Order
    {
        $locked = Order::lockForUpdate()->findOrFail($order->id);
        if ($locked->order_status !== $from) {
            $this->fail('The order status has changed. Reload the page before continuing.');
        }

        return $locked;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['status' => $message]);
    }
}
