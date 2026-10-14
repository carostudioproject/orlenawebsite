<?php

namespace App\Services\Ordering;

use App\Models\Order;
use App\Models\Outlet;
use App\Support\PreorderDate;
use Carbon\CarbonImmutable;

/**
 * Rules Order Website (Revisi 2), section 6: each outlet takes a daily quota of orders (pickup and delivery from it)
 * and at most {hourly_quota} pickups per hourly slot. Cancelled orders do not count, so their slot is free again.
 */
class Capacity
{
    public function __construct(private PreorderDate $dates) {}

    /** Hourly slot start times ("13:00", "14:00", …): the last slot starts an hour before the outlet closes. */
    public function slots(Outlet $outlet): array
    {
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $slots = [];
        for ($start = $minutes($outlet->first_slot); $start + 60 <= $minutes($outlet->closes_at); $start += 60) {
            $slots[] = sprintf('%02d:%02d', intdiv($start, 60), $start % 60);
        }

        return $slots;
    }

    /**
     * Every date a customer can see on the calendar (tomorrow up to the 30-day limit) for an outlet:
     * status open | cutoff | closed | full, the reason when not open, and the slots with the places left (null = no hourly limit).
     */
    public function calendar(Outlet $outlet, string $method): array
    {
        $now = CarbonImmutable::now('Asia/Makassar');
        $latest = $this->dates->latest($now);
        [$days, $hours] = $this->load($outlet, $now->addDay()->toDateString(), $latest);
        $closed = $this->dates->closedReasons($now->addDay()->toDateString(), $latest);
        $calendar = [];
        for ($day = $now->addDay(); $day->toDateString() <= $latest; $day = $day->addDay()) {
            $date = $day->toDateString();
            $slots = $this->slotsLeft($outlet, $method, $hours[$date] ?? []);
            [$status, $reason] = match (true) {
                $now->greaterThan($this->dates->cutoff($date)) => ['cutoff', 'Lewat batas pemesanan ('.$this->dates->cutoffLabel().').'],
                isset($closed[$date]) => ['closed', $closed[$date]],
                ($days[$date] ?? 0) >= $outlet->daily_quota || ! array_filter($slots, fn ($left) => $left === null || $left > 0) => ['full', 'Kuota '.$outlet->name.' untuk tanggal ini sudah penuh.'],
                default => ['open', null],
            };
            $calendar[] = ['date' => $date, 'status' => $status, 'reason' => $reason,
                'slots' => $status === 'open' ? array_map(fn ($time, $left) => ['time' => $time, 'left' => $left], array_keys($slots), $slots) : []];
        }

        return $calendar;
    }

    /** Why this outlet cannot take another order on that date and time, or null. Staff edits pass the order itself as $except. */
    public function problem(Outlet $outlet, string $date, ?string $time, string $method, ?int $except = null, bool $english = false): ?string
    {
        [$days, $hours] = $this->load($outlet, $date, $date, $except);
        if (($days[$date] ?? 0) >= $outlet->daily_quota) {
            return $english ? $outlet->name.' is full on '.$date.' ('.$outlet->daily_quota.' orders a day).'
                : 'Kuota '.$outlet->name.' untuk tanggal ini sudah penuh. Pilih tanggal atau outlet lain.';
        }
        $hour = $time ? substr($time, 0, 2) : null;
        if ($method === 'pickup' && $hour !== null && ($hours[$date][$hour] ?? 0) >= $outlet->hourly_quota) {
            return $english ? 'The '.$hour.':00 pickup slot at '.$outlet->name.' is full ('.$outlet->hourly_quota.' orders an hour).'
                : 'Slot jam '.$hour.'.00 di '.$outlet->name.' sudah penuh. Pilih jam lain.';
        }

        return null;
    }

    /** The first open date with room for this order at its outlet (paid after the cutoff), from the earliest PO date. */
    public function nextOpenDate(Order $order): string
    {
        $outlet = $order->outlet;
        $date = CarbonImmutable::parse($this->dates->earliest(), 'Asia/Makassar');
        for ($i = 0; $outlet && $i < 60; $i++, $date = $date->addDay()) {
            if (! $this->dates->closedReason($date->toDateString()) && ! $this->problem($outlet, $date->toDateString(), $order->requested_time, $order->fulfillment_method, $order->id)) {
                return $date->toDateString();
            }
        }

        // Every date is full: the earliest date anyway; staff see the move on the order page.
        return $this->dates->earliest();
    }

    /** Places left per slot; delivery times are an estimate and have no hourly limit. */
    private function slotsLeft(Outlet $outlet, string $method, array $hours): array
    {
        $slots = [];
        foreach ($this->slots($outlet) as $slot) {
            $slots[$slot] = $method === 'pickup' ? max(0, $outlet->hourly_quota - ($hours[substr($slot, 0, 2)] ?? 0)) : null;
        }

        return $slots;
    }

    /** Active orders per date at the outlet, and pickups per date and hour. */
    private function load(Outlet $outlet, string $from, string $to, ?int $except = null): array
    {
        $orders = Order::where('outlet_id', $outlet->id)->where('order_status', '!=', 'cancelled')
            ->whereDate('requested_date', '>=', $from)->whereDate('requested_date', '<=', $to)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->get(['requested_date', 'requested_time', 'fulfillment_method']);
        $days = [];
        $hours = [];
        foreach ($orders as $order) {
            $date = $order->requested_date->toDateString();
            $days[$date] = ($days[$date] ?? 0) + 1;
            if ($order->fulfillment_method === 'pickup' && $order->requested_time) {
                $hour = substr($order->requested_time, 0, 2);
                $hours[$date][$hour] = ($hours[$date][$hour] ?? 0) + 1;
            }
        }

        return [$days, $hours];
    }
}
