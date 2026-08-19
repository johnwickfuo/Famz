<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sellers must not wait on a buyer who never confirms. Hourly rather than
// daily so the window a seller was promised is roughly the window they get.
Schedule::command('escrow:release')->hourly()->withoutOverlapping();

// Checks the mode and the configured payout day itself, so an administrator
// changing either on a settings screen changes when sellers are paid.
Schedule::command('payouts:run')->dailyAt('09:00')->withoutOverlapping();

// Hourly rather than daily: an offer promised as good for 72 hours should not
// still be acceptable most of a day after it lapsed.
Schedule::command('offers:sweep')->hourly()->withoutOverlapping();
