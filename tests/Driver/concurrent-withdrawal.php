<?php

use App\Models\User;
use App\Services\Payouts\WithdrawalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/*
 * One withdrawal request, in its own process.
 *
 * Spawned several times over by ConcurrentWithdrawalTest so that real
 * processes race for the same balance. Nothing here can be faked: the lock in
 * WithdrawalService either serialises these or it does not.
 *
 * Usage: php concurrent-withdrawal.php <user-id> <amount-kobo> <unix-start-time>
 */

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = (int) ($argv[1] ?? 0);
$amountKobo = (int) ($argv[2] ?? 0);
$startAt = (float) ($argv[3] ?? 0);

// Every process sleeps until the same instant, so they arrive together rather
// than politely one after another.
$waitMicroseconds = (int) (($startAt - microtime(true)) * 1_000_000);

if ($waitMicroseconds > 0) {
    usleep($waitMicroseconds);
}

try {
    $withdrawal = $app->make(WithdrawalService::class)->request(
        User::query()->findOrFail($userId),
        $amountKobo,
    );

    fwrite(STDOUT, 'OK '.$withdrawal->reference."\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'NO '.$exception->getMessage()."\n");
}
