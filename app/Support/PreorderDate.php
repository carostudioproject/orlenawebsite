<?php

namespace App\Support;

use App\Models\ClosedDate;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * PO schedule rules, set by staff under Pengaturan → Jadwal PO:
 * production for a date closes at H-1 {cutoff time} WITA, and closed weekdays/dates take no PO.
 */
class PreorderDate
{
    public const DEFAULT_CUTOFF = '18:00';

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

    /** Carbon day-of-week numbers (0 = Sunday) without PO. */
    public function closedWeekdays(): array
    {
        return array_map('intval', Setting::get('po.closed_weekdays', []));
    }

    /** Orders per requested date staff can handle; a warning only, never a block. */
    public function dailyCapacity(): ?int
    {
        $capacity = Setting::get('po.daily_capacity');

        return $capacity ? (int) $capacity : null;
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
