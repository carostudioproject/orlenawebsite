<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Services\Ordering\Capacity;
use App\Support\Audit;
use App\Support\PreorderDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewOrder
{
    /** Totals and schedule stay editable until a payment link is open or paid. */
    private const EDITABLE_PAYMENT = ['not_created', 'failed', 'expired', 'cancelled'];

    public function handle(Order $order, array $data, int $actor): void
    {
        DB::transaction(function () use ($order, $data, $actor) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->order_status, ['pending_review', 'confirmed'], true) || ! in_array($locked->payment_status, self::EDITABLE_PAYMENT, true)
                || Payment::where('open_order_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['review' => 'Review is locked because a payment link is open, payment was received, or the order is already being processed.']);
            }
            if ((int) $locked->review_version !== (int) $data['review_version']) {
                throw ValidationException::withMessages(['review' => 'The order was updated by the team. Reload the page and check the changes before saving again.']);
            }
            $fee = $data['delivery_fee'] === null ? null : (int) $data['delivery_fee'];
            if ($locked->fulfillment_method === 'pickup' && $fee !== 0) {
                throw ValidationException::withMessages(['delivery_fee' => 'Pickup orders must have a Rp 0 delivery fee.']);
            }
            if ($locked->order_status === 'confirmed' && $fee === null) {
                throw ValidationException::withMessages(['delivery_fee' => 'Confirmed orders must have a delivery fee.']);
            }
            $previousSchedule = $this->schedule($locked->requested_date->toDateString(), $locked->requested_time);
            $date = $data['requested_date'] ?? $locked->requested_date->toDateString();
            $time = array_key_exists('requested_time', $data) ? $data['requested_time'] : $locked->requested_time;
            $schedule = $this->schedule($date, $time);
            $dates = app(PreorderDate::class);
            if ($date !== $locked->requested_date->toDateString() && $date < ($earliest = $dates->earliest())) {
                throw ValidationException::withMessages(['requested_date' => 'The new date must be '.$earliest.' or later (order deadline '.$dates->cutoffLabel(english: true).').']);
            }
            if ($date !== $locked->requested_date->toDateString() && $date > ($latest = $dates->latest())) {
                throw ValidationException::withMessages(['requested_date' => 'The new date can be at most '.$dates->maxDays().' days ahead ('.$latest.').']);
            }
            if ($date !== $locked->requested_date->toDateString() && $closed = $dates->closedReason($date, english: true)) {
                throw ValidationException::withMessages(['requested_date' => $closed.' Reopen it under PO schedule if this was agreed.']);
            }
            $outlet = $locked->outlet;
            $outletChanged = isset($data['outlet_id']) && (int) $data['outlet_id'] !== $locked->outlet_id;
            if ($outletChanged) {
                $outlet = Outlet::takingPreorders()->when($locked->fulfillment_method === 'delivery', fn ($query) => $query->where('delivers', true))->find($data['outlet_id']);
                if (! $outlet) {
                    throw ValidationException::withMessages(['outlet_id' => $locked->fulfillment_method === 'delivery' ? 'Choose an active outlet that ships delivery.' : 'Choose an active outlet that takes PO.']);
                }
            }
            // A new date, time or outlet must fit the outlet's daily quota and hourly pickup slot (this order not counted).
            if (($outletChanged || $schedule !== $previousSchedule) && $outlet
                && $full = app(Capacity::class)->problem($outlet, $date, $time, $locked->fulfillment_method, $locked->id, english: true)) {
                throw ValidationException::withMessages(['requested_date' => $full]);
            }
            $previous = $locked->delivery_fee;
            // A deadline set while the customer chooses VA BCA or QRIS never passes the new date's H-1 payment close.
            $dueAt = $locked->payment_due_at && $date !== $locked->requested_date->toDateString()
                ? $locked->payment_due_at->toImmutable()->min($dates->paymentClose($date)) : $locked->payment_due_at;
            $locked->update([
                'delivery_fee' => $fee, 'total' => $locked->subtotal + ($fee ?? 0), 'review_version' => $locked->review_version + 1,
                'requested_date' => $date, 'requested_time' => $time, 'payment_due_at' => $dueAt,
            ] + ($outletChanged ? ['outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name, 'nearest_outlet' => false] : []));
            $changed = $schedule !== $previousSchedule;
            DB::table('order_reviews')->insert([
                'order_id' => $locked->id, 'actor_id' => $actor, 'previous_delivery_fee' => $previous, 'delivery_fee' => $fee, 'note' => $data['note'],
                'previous_schedule' => $changed ? $previousSchedule : null, 'schedule' => $changed ? $schedule : null, 'created_at' => now(),
            ]);
            Audit::record('order.reviewed', $locked, ['previous_delivery_fee' => $previous, 'delivery_fee' => $fee, 'review_version' => $locked->review_version]
                + ($changed ? ['previous_schedule' => $previousSchedule, 'schedule' => $schedule] : [])
                + ($outletChanged ? ['outlet' => $outlet->name] : []), $actor);
        }, 3);
    }

    private function schedule(string $date, ?string $time): string
    {
        return trim($date.' '.($time ? substr($time, 0, 5) : ''));
    }
}
