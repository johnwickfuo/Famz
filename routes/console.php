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
