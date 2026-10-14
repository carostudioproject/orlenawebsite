<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hostinger cron runs `php artisan schedule:run` every minute; no long-running worker is required.
// Hostinger may disable proc_open, which Schedule::command needs to start a separate process,
// so each task runs inside schedule:run itself.
$artisan = fn (string $command) => fn () => Artisan::call($command);
// Heartbeat: Developer tools shows when the cron last ran, so a missing cron job is noticed.
Schedule::call(fn () => Cache::forever('scheduler:last-run', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
// Rules Order Website: still unpaid at H-1 payment close (21.00) → cancelled, so the slot is free again.
Schedule::call($artisan('orders:cancel-unpaid'))->name('orders:cancel-unpaid')->everyMinute()->withoutOverlapping();
Schedule::call($artisan('payments:reconcile'))->name('payments:reconcile')->everyTenMinutes()->withoutOverlapping();
// Sends paid transactions to Erzap; rows wait quietly while Erzap is not configured yet.
Schedule::call($artisan('erzap:sync'))->name('erzap:sync')->everyFiveMinutes()->withoutOverlapping();
