<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly: rebuild the delivered-worktime ledger for the last ~5 weeks so
// completed days settle (the device API does not recompute inline).
Schedule::command('worktime:recalc --days=35')->dailyAt('02:30');

// Yearly: import the next year's public holidays (and refresh the current one)
// shortly before year end, per the configured Bundesland.
Schedule::command('holidays:sync')->yearlyOn(12, 1, '03:00');
Schedule::call(function () {
    app(\App\Services\HolidayService::class)->sync(now()->addYear()->year);
})->yearlyOn(12, 1, '03:05');
