<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hostinger cron runs `php artisan schedule:run` every minute; no long-running worker is required.
Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping();
// Sends paid transactions to Erzap; rows wait quietly while Erzap is not configured yet.
Schedule::command('erzap:sync')->everyFiveMinutes()->withoutOverlapping();
// Rules Order Website: still unpaid at H-1 payment close (21.00) → cancelled, so the slot is free again.
Schedule::command('orders:cancel-unpaid')->everyMinute()->withoutOverlapping();
// Heartbeat: Developer tools shows when the cron last ran, so a missing cron job is noticed.
Schedule::call(fn () => Cache::forever('scheduler:last-run', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
