<?php

namespace App\Console\Commands;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Payments\ReconcilePayment;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Doku\DokuException;
use App\Support\PreorderDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Rules Order Website: an order still unpaid at H-1 payment close (21.00 WITA) is cancelled and its slot is free again.
 * Open links are checked at DOKU first, so a payment made in the last minutes is recorded instead of cancelled.
 * Partly paid orders are left for staff (they need a top-up or a refund).
 */
class CancelUnpaidOrders extends Command
{
    protected $signature = 'orders:cancel-unpaid {--limit=50}';

    protected $description = 'Cancel orders still unpaid at their H-1 payment close';

    public function handle(PreorderDate $dates, ReconcilePayment $reconcile, ChangeOrderStatus $status): int
    {
        $now = CarbonImmutable::now('Asia/Makassar');
        // The latest requested date whose payment close (D-1 at the close time) has passed.
        $closedUpTo = $now->greaterThanOrEqualTo($dates->paymentClose($now->addDay()->toDateString())) ? $now->addDay() : $now;
        $orders = Order::whereIn('order_status', ['pending_review', 'confirmed'])->whereNotIn('payment_status', ['paid', 'refunded'])
            ->whereDate('requested_date', '<=', $closedUpTo->toDateString())
            ->whereDoesntHave('payments', fn ($query) => $query->where('status', 'paid'))
            ->orderBy('id')->limit((int) $this->option('limit'))->get();
        $cancelled = 0;
        foreach ($orders as $order) {
            try {
                foreach (Payment::where('order_id', $order->id)->where('status', 'pending')->get() as $payment) {
                    $reconcile->handle($payment);
                }
            } catch (DokuException $e) {
                $this->warn($order->order_code.': status not checked ('.$e->getMessage().'), retried next minute.');

                continue;
            }
            $order->refresh();
            if (! in_array($order->order_status, ['pending_review', 'confirmed'], true) || $order->payment_status === 'paid') {
                continue;
            }
            try {
                $closed = $status->cancel($order, $order->order_status, 'Otomatis: belum dibayar sampai batas pembayaran ('.$dates->paymentCloseLabel().').', null,
                    fn (Order $locked) => $locked->payment_status !== 'paid' && $now->greaterThanOrEqualTo($dates->paymentClose($locked->requested_date->toDateString()))
                        && ! Payment::where('order_id', $locked->id)->where('status', 'paid')->exists());
            } catch (ValidationException) {
                continue;
            }
            $cancelled++;
            if (! $closed) {
                $this->warn($order->order_code.': cancelled, but a DOKU link could not be closed.');
            }
        }
        $this->info('Cancelled '.$cancelled.' unpaid order(s).');

        return self::SUCCESS;
    }
}
