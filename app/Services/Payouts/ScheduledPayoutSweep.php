<?php

namespace App\Services\Payouts;

use App\Enums\LedgerState;
use App\Enums\PayoutMode;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The automatic payout run.
 *
 * On the configured day of the month, every balance above the minimum is paid
 * out without anybody having to ask. Sellers on a market stall should not have
 * to remember to file a request to get money they have already earned.
 *
 * The sweep is idempotent by day: running it twice on the same date pays
 * nobody twice, because a seller with a request already in flight has nothing
 * left to reserve against.
 */
class ScheduledPayoutSweep
{
    public function __construct(
        private readonly WithdrawalService $withdrawals,
        private readonly PayoutAccountService $accounts,
    ) {}

    /**
     * Whether today is a payout day.
     *
     * A schedule day of 31 in February would otherwise never fire, so anything
     * past the end of the month lands on the last day of it.
     */
    public function isDueOn(CarbonInterface $date): bool
    {
        $configured = (int) settings('payout_schedule_day', 28);
        $day = max(1, min($configured, $date->daysInMonth));

        return $date->day === $day;
    }

    public function isEnabled(): bool
    {
        return PayoutMode::current() === PayoutMode::ScheduledAuto;
    }

    /**
     * Create and send a payout for everybody who is owed one.
     *
     * @return array{swept: int, skipped: int, total_kobo: int, problems: array<int, string>}
     */
    public function run(bool $send = true): array
    {
        $swept = 0;
        $skipped = 0;
        $total = 0;
        $problems = [];

        foreach ($this->eligibleUserIds() as $userId) {
            $user = User::query()->find($userId);

            if ($user === null) {
                continue;
            }

            $amount = $this->withdrawals->requestableBalance($user);

            if ($amount < $this->withdrawals->minimumKobo()) {
                $skipped++;

                continue;
            }

            $account = $this->accounts->defaultFor($user);

            if ($account === null) {
                // Nowhere to send it. Not an error — plenty of sellers have
                // simply not added an account yet — but worth reporting.
                $skipped++;
                $problems[] = __(':name has :amount waiting but no bank account.', [
                    'name' => $user->displayName(),
                    'amount' => Money::fromKobo($amount),
                ]);

                continue;
            }

            try {
                $withdrawal = $this->withdrawals->request($user, $amount, $account);

                // Nobody approves a scheduled payout; the schedule is the
                // approval.
                if ($send) {
                    $this->withdrawals->process($withdrawal);
                }

                $swept++;
                $total += $amount;
            } catch (RuntimeException $exception) {
                $skipped++;
                $problems[] = $user->displayName().': '.$exception->getMessage();
            }
        }

        return ['swept' => $swept, 'skipped' => $skipped, 'total_kobo' => $total, 'problems' => $problems];
    }

    /**
     * Everybody with any spendable ledger entry at all.
     *
     * Deliberately a cheap first pass: the real test is `requestableBalance`,
     * which is per user and takes a lock, so this only has to avoid walking
     * the whole users table.
     *
     * @return Collection<int, int>
     */
    private function eligibleUserIds(): Collection
    {
        return WalletTransaction::query()
            ->whereNotNull('user_id')
            ->whereIn('state', LedgerState::spendable())
            ->groupBy('user_id')
            ->havingRaw('SUM(amount_kobo) >= ?', [$this->withdrawals->minimumKobo()])
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id);
    }
}
