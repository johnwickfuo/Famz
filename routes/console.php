<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

/*
 * Turning the marketplace's own listings into prices the assistant can quote.
 *
 * Daily and early, before anybody is asking. The figures are only as good as
 * the listings behind them, which is the honest trade for numbers that can be
 * cited with a sample size and a date.
 */
Schedule::command('market:capture-prices')->dailyAt('04:00')->withoutOverlapping();

/*
 * The nightly reconciliation.
 *
 * Runs at 02:30, after the day's escrow releases and before anybody is awake to
 * be confused by a partial picture. `--alert` means administrators hear about a
 * discrepancy the same night rather than whenever somebody next opens the admin
 * panel; a silent run says nothing, because a job that reports success three
 * hundred times a year is a job whose emails get filtered.
 *
 * withoutOverlapping because on a large ledger this can take minutes, and two
 * copies reading the same tables would only produce the same answer twice.
 */
Schedule::command('ledger:reconcile --alert')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        // The reconciliation FAILING is itself worth recording: it means the
        // safety net did not run, which looks identical to everything being
        // fine from the outside.
        Log::channel('reconciliation')
            ->critical('The nightly reconciliation did not complete.');
    });

/*
 * Backups.
 *
 * Cleanup first, then the backup itself: running them the other way round
 * means the disk is at its fullest exactly when a new archive needs room.
 *
 * The monitor runs in the morning and complains if the newest backup is older
 * than it should be — which is the failure that matters, because a backup job
 * that silently stopped a fortnight ago looks exactly like one that is working.
 */
Schedule::command('backup:clean')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('08:00');

/*
 * And a restore test, weekly.
 *
 * An untested backup is a hope. This one downloads the newest archive, restores
 * it into a scratch database and counts what came back — the only way to learn
 * that the archive is truncated BEFORE the day somebody needs it.
 */
Schedule::command('backup:verify-restore')->weeklyOn(1, '03:30')->withoutOverlapping();
