<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Ordering\Capacity;
use App\Support\Audit;
use App\Support\PreorderDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rules Order Website: after payment the date can change once, by staff, before the current date's H-1 cutoff.
 * Only the date and time change (never the outlet), so the sale already sent to Erzap stays right and is not sent again;
 * the PO date there is only in the note text.
 */
class RescheduleOrder
{
    public const STATUSES = ['confirmed', 'processing', 'ready'];

    public function __construct(private PreorderDate $dates, private Capacity $capacity) {}

    /** Why this order cannot be rescheduled now, or null. */
    public function blocker(Order $order): ?string
    {
        return match (true) {
            $order->payment_status !== 'paid' || ! in_array($order->order_status, self::STATUSES, true) => 'Only paid orders that are not completed or cancelled can be rescheduled.',
            $order->rescheduled_at !== null => 'This order was already rescheduled once after payment.',
            now('Asia/Makassar')->greaterThan($this->dates->cutoff($order->requested_date->toDateString())) => 'The cut-off for the current date has passed ('.$this->dates->cutoffLabel(english: true).'), so the order can no longer be changed.',
            default => null,
        };
    }

    public function handle(Order $order, string $date, string $time, string $note, User $actor): void
    {
        DB::transaction(function () use ($order, $date, $time, $note, $actor) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if ($blocker = $this->blocker($locked)) {
                $this->fail('reschedule', $blocker);
            }
            $from = $locked->requested_date->toDateString();
            $previous = trim($from.' '.substr((string) $locked->requested_time, 0, 5));
            if ($previous === $date.' '.$time) {
                $this->fail('requested_date', 'Choose a different date or time.');
            }
            if ($date < ($earliest = $this->dates->earliest()) || $date > ($latest = $this->dates->latest())) {
                $this->fail('requested_date', 'Choose a date from '.$earliest.' to '.$latest.'.');
            }
            if ($closed = $this->dates->closedReason($date, english: true)) {
                $this->fail('requested_date', $closed);
            }
            if ($locked->outlet && $full = $this->capacity->problem($locked->outlet, $date, $time, $locked->fulfillment_method, $locked->id, english: true)) {
                $this->fail('requested_date', $full);
            }
            $locked->update([
                'requested_date' => $date, 'requested_time' => $time, 'rescheduled_from' => $from, 'rescheduled_at' => now(),
                'review_version' => $locked->review_version + 1,
            ]);
            DB::table('order_reviews')->insert([
                'order_id' => $locked->id, 'actor_id' => $actor->id, 'previous_delivery_fee' => $locked->delivery_fee, 'delivery_fee' => $locked->delivery_fee,
                'note' => 'Rescheduled after payment: '.$note, 'previous_schedule' => $previous, 'schedule' => $date.' '.$time, 'created_at' => now(),
            ]);
            Audit::record('order.rescheduled', $locked, ['from' => $previous, 'to' => $date.' '.$time], $actor->id);
        }, 3);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
