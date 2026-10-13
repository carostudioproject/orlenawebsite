<?php

namespace App\Support;

use App\Models\ClosedDate;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * PO schedule rules, set by staff under Pengaturan → Jadwal PO (Rules Order Website):
 * production for a date closes at H-1 {cutoff time} WITA, unpaid orders are cancelled at H-1 {payment close} WITA,
 * dates go up to {max days} ahead, and closed weekdays/dates take no PO.
 */
class PreorderDate
{
    public const DEFAULT_CUTOFF = '19:00';

    public const DEFAULT_PAYMENT_CLOSE = '21:00';

    public const DEFAULT_MAX_DAYS = 30;

    public const WEEKDAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 0 => 'Minggu'];

    /** The dashboard is in English; customer pages stay Indonesian. */
    public const WEEKDAYS_EN = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

    public function cutoffTime(): string
    {
        return Setting::get('po.cutoff_time', self::DEFAULT_CUTOFF);
    }

    /** e.g. "H-1 pukul 18.00 WITA" for customers, "D-1 at 18:00 WITA" for the dashboard. */
    public function cutoffLabel(bool $english = false): string
    {
        return $english ? 'D-1 at '.$this->cutoffTime().' WITA' : 'H-1 pukul '.str_replace(':', '.', $this->cutoffTime()).' WITA';
    }

    /** Payments received between the cutoff and this time move the order to the next open date; still unpaid at this time, it is cancelled. */
    public function paymentCloseTime(): string
    {
        return Setting::get('po.payment_close_time', self::DEFAULT_PAYMENT_CLOSE);
    }

    public function paymentCloseLabel(bool $english = false): string
    {
        return $english ? 'D-1 at '.$this->paymentCloseTime().' WITA' : 'H-1 pukul '.str_replace(':', '.', $this->paymentCloseTime()).' WITA';
    }

    public function paymentClose(string $requestedDate): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->paymentCloseTime()));

        return CarbonImmutable::parse($requestedDate, 'Asia/Makassar')->subDay()->setTime($hour, $minute);
    }

    /** How many days ahead customers may order. */
    public function maxDays(): int
    {
        return (int) Setting::get('po.max_days', self::DEFAULT_MAX_DAYS);
    }

    /** The last date that can be ordered today. */
    public function latest(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now('Asia/Makassar'))->setTimezone('Asia/Makassar')->addDays($this->maxDays())->toDateString();
    }

    /** Carbon day-of-week numbers (0 = Sunday) without PO. */
    public function closedWeekdays(): array
    {
        return array_map('intval', Setting::get('po.closed_weekdays', []));
    }

    /** Delivery arrival is an estimate: minutes late without compensation, shown before the customer pays. */
    public function deliveryTolerance(): int
    {
        return (int) Setting::get('delivery.tolerance_minutes', 60);
    }

    public function cutoff(string $requestedDate): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->cutoffTime()));

        return CarbonImmutable::parse($requestedDate, 'Asia/Makassar')->subDay()->setTime($hour, $minute);
    }

    /** Why a date takes no PO, or null when it is open. */
    public function closedReason(string $date, bool $english = false): ?string
    {
        $day = CarbonImmutable::parse($date, 'Asia/Makassar');
        if (in_array($day->dayOfWeek, $this->closedWeekdays(), true)) {
            return $english ? 'Orlena takes no PO on '.self::WEEKDAYS_EN[$day->dayOfWeek].'s.' : 'Orlena tidak menerima PO untuk hari '.self::WEEKDAYS[$day->dayOfWeek].'.';
        }
        $closed = ClosedDate::whereDate('date', $day->toDateString())->first();
        if (! $closed) {
            return null;
        }
        $reason = $closed->reason ? ' ('.$closed->reason.')' : '';

        return $english ? 'This date is closed for PO'.$reason.'.' : 'Tanggal ini tutup untuk PO'.$reason.'.';
    }

    /** The first open date whose cutoff has not passed. */
    public function earliest(?CarbonImmutable $now = null): string
    {
        $now = ($now ?? CarbonImmutable::now('Asia/Makassar'))->setTimezone('Asia/Makassar');
        $closedDates = ClosedDate::whereDate('date', '>', $now->toDateString())->pluck('date')->map->toDateString()->all();
        $weekdays = $this->closedWeekdays();
        $date = $now->addDay()->startOfDay();
        // A full week of closed weekdays is refused in settings, so this always ends within a few months.
        for ($i = 0; $i < 366; $i++, $date = $date->addDay()) {
            if ($now->lessThanOrEqualTo($this->cutoff($date->toDateString())) && ! in_array($date->dayOfWeek, $weekdays, true) && ! in_array($date->toDateString(), $closedDates, true)) {
                return $date->toDateString();
            }
        }

        return $date->toDateString();
    }

    /** Upcoming closed dates for the order form (client-side hint; the server checks again). */
    public function upcomingClosedDates(): array
    {
        return ClosedDate::whereDate('date', '>=', now('Asia/Makassar')->toDateString())->orderBy('date')->limit(100)->get(['date', 'reason'])
            ->map(fn ($closed) => ['date' => $closed->date->toDateString(), 'reason' => $closed->reason])->all();
    }
}
