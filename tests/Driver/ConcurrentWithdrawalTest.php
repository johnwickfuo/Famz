<?php

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Symfony\Component\Process\Process;

/**
 * The double-spend guard, tested the only way it can honestly be tested.
 *
 * A single-process test proves nothing about a race: whatever order the calls
 * are written in is the order they run. So this spawns real OS processes,
 * lines them up on a common start time, and lets them fight over one balance.
 * Only a real row lock survives it, which is why this lives in tests/Driver
 * and needs a real MySQL — SQLite has no `FOR UPDATE` to test.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->wallet = app(WalletService::class);

    $this->seller = User::factory()->create();
    PayoutAccount::factory()->for($this->seller)->create();

    // ₦50,000, and not a kobo more.
    $this->wallet->record($this->seller, LedgerType::Sale, 5_000_000, LedgerState::Released, 'Sale');
});

/**
 * Run `$count` withdrawal requests at once and return what each said.
 *
 * @return array<int, string>
 */
function raceForWithdrawals(int $userId, int $amountKobo, int $count, string $database): array
{
    $startAt = microtime(true) + 1.5;

    $processes = [];

    foreach (range(1, $count) as $ignored) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Driver/concurrent-withdrawal.php'), (string) $userId, (string) $amountKobo, (string) $startAt],
            base_path(),
            [
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'APP_ENV' => 'testing',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
            ],
        );

        $process->setTimeout(60);
        $process->start();

        $processes[] = $process;
    }

    $output = [];

    foreach ($processes as $process) {
        $process->wait();
        $output[] = trim($process->getOutput().$process->getErrorOutput());
    }

    return $output;
}

it('lets exactly one of several simultaneous requests take the whole balance', function () {
    $results = raceForWithdrawals($this->seller->id, 5_000_000, 6, $this->mysqlDatabase());

    $accepted = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK')));

    expect($accepted)->toHaveCount(1, "every process said:\n".implode("\n", $results));

    // And the books agree: one request, for exactly what was there.
    expect(Withdrawal::query()->count())->toBe(1)
        ->and((int) Withdrawal::query()->sum('amount_kobo'))->toBe(5_000_000);
});

it('never lets simultaneous requests add up to more than the balance', function () {
    // Six processes each asking for ₦20,000 out of ₦50,000: two can be paid,
    // and the arithmetic must stop the third.
    $results = raceForWithdrawals($this->seller->id, 2_000_000, 6, $this->mysqlDatabase());

    $accepted = count(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK')));
    $requested = (int) Withdrawal::query()->sum('amount_kobo');

    expect($accepted)->toBe(2, "every process said:\n".implode("\n", $results))
        ->and($requested)->toBe(4_000_000)
        ->and($requested)->toBeLessThanOrEqual($this->wallet->availableBalance($this->seller));
});

it('leaves the balance whole when every request is refused', function () {
    $results = raceForWithdrawals($this->seller->id, 9_000_000, 4, $this->mysqlDatabase());

    expect(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK')))->toBeEmpty()
        ->and(Withdrawal::query()->count())->toBe(0)
        ->and($this->wallet->availableBalance($this->seller))->toBe(5_000_000);
});
