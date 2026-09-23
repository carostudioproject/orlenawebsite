<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hostinger cron runs `php artisan schedule:run` every minute; no long-running worker is required.
Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping();
// Sends paid transactions to Erzap; rows wait quietly while Erzap is not configured yet.
Schedule::command('erzap:sync')->everyFiveMinutes()->withoutOverlapping();
