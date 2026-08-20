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

/*
 * Mentorship: confirming work nobody answered for, and billing the next period.
 * Hourly, so the seven days a client was promised is roughly the seven days
 * they get rather than up to eight.
 */
Schedule::command('mentorship:sweep')->hourly()->withoutOverlapping();

/*
 * Farm setup proposals lapsing.
 *
 * Daily rather than hourly, because validity is measured in days: a proposal
 * valid until the 30th is valid all of the 30th, and there is nothing to gain
 * from telling somebody at 01:00 on the 31st rather than at 07:00. Early, so
 * the notice is in the client's inbox before the working day starts.
 */
Schedule::command('quotations:expire')->dailyAt('07:00')->withoutOverlapping();

/*
 * Job listings closing on the deadline their employer set.
 *
 * Daily and early: a deadline of the 30th means the 30th, and a worker
 * checking the board over breakfast should not see a job that shut at
 * midnight.
 */
Schedule::command('jobs:expire')->dailyAt('06:00')->withoutOverlapping();
