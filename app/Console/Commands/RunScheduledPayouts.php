<?php

namespace App\Console\Commands;

use App\Services\Payouts\ScheduledPayoutSweep;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * The automatic payout run.
 *
 * Scheduled daily and does nothing on most days: it checks the mode and the
 * configured day itself rather than being wired to a cron expression, so an
 * administrator changing the payout day on a settings screen changes when
 * sellers are paid, with no deploy.
 */
class RunScheduledPayouts extends Command
{
    protected $signature = 'payouts:run
                            {--force : Run today even if today is not the payout day}
                            {--pretend : Create the payouts but do not send them}';

    protected $description = 'Pay every seller balance above the minimum, on the configured day';

    public function handle(ScheduledPayoutSweep $sweep): int
    {
        if (! $sweep->isEnabled() && ! $this->option('force')) {
            $this->line('Automatic payouts are switched off. Nothing to do.');

            return self::SUCCESS;
        }

        if (! $sweep->isDueOn(now()) && ! $this->option('force')) {
            $this->line('Today is not the payout day. Nothing to do.');

            return self::SUCCESS;
        }

        $result = $sweep->run(send: ! $this->option('pretend'));

        $this->info(sprintf(
            'Paid %d seller(s), %s in total. %d skipped.',
            $result['swept'],
            Money::fromKobo($result['total_kobo']),
            $result['skipped'],
        ));

        foreach ($result['problems'] as $problem) {
            $this->warn('  · '.$problem);
        }

        return self::SUCCESS;
    }
}
