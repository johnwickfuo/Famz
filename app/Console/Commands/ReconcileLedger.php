<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ReconciliationAlert;
use App\Services\Reporting\ReconciliationReport;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ask the books whether they still agree, every night.
 *
 * Two audiences. Run by hand it prints a table somebody can read; run by the
 * scheduler it says nothing at all unless something is wrong, because a nightly
 * job that emails "everything is fine" three hundred times a year is a nightly
 * job whose emails get filtered — and the one that matters goes to the same
 * folder.
 *
 * The exit code is meaningful: non-zero when a critical finding is present, so
 * an external monitor can watch this without parsing anything.
 */
class ReconcileLedger extends Command
{
    protected $signature = 'ledger:reconcile
        {--from= : Only consider records from this date}
        {--until= : Only consider records up to this date}
        {--alert : Notify administrators if anything is wrong}';

    protected $description = 'Verify that every account, order and gateway settlement still agrees';

    public function handle(ReconciliationReport $report): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from'))->startOfDay() : null;
        $until = $this->option('until') ? Carbon::parse((string) $this->option('until'))->endOfDay() : null;

        $result = $report->run($from, $until);

        $this->renderChecks($result);

        if ($result['clean']) {
            $this->info('Everything agrees.');

            return self::SUCCESS;
        }

        $this->renderFindings($result);

        if ($this->option('alert')) {
            $this->alertAdministrators($result);
        }

        /*
         * Logged whether or not anybody is alerted, because the log is what
         * somebody reads afterwards to work out when a problem started — and
         * "when did this begin" is the first question every time.
         */
        Log::channel('reconciliation')->error('Reconciliation found discrepancies.', [
            'critical' => $result['critical'],
            'findings' => count($result['findings']),
            'detail' => array_slice($result['findings'], 0, 20),
        ]);

        // Non-zero only for the critical ones. A warning should be visible
        // without waking anybody at three in the morning.
        return $result['critical'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderChecks(array $result): void
    {
        $this->table(
            ['Check', 'Records', 'Problems'],
            collect($result['checks'])->map(fn (array $check): array => [
                $check['label'],
                $check['checked'],
                count($check['findings']) ?: '—',
            ])->all(),
        );

        $totals = $result['totals'];

        $this->line(sprintf(
            '  Collected %s · credited %s · difference %s',
            Money::fromKobo($totals['collected_kobo']),
            Money::fromKobo($totals['net_ledger_kobo']),
            Money::fromKobo($totals['difference_kobo']),
        ));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderFindings(array $result): void
    {
        $this->newLine();
        $this->error(sprintf(
            '%d discrepanc%s found (%d critical).',
            count($result['findings']),
            count($result['findings']) === 1 ? 'y' : 'ies',
            $result['critical'],
        ));

        foreach ($result['findings'] as $finding) {
            $this->line(sprintf(
                '  <fg=%s>%s</> %s — %s',
                $finding['severity'] === ReconciliationReport::SEVERITY_CRITICAL ? 'red' : 'yellow',
                str_pad(strtoupper($finding['severity']), 8),
                $finding['subject'],
                $finding['message'],
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function alertAdministrators(array $result): void
    {
        $admins = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
            ->get();

        if ($admins->isEmpty()) {
            // Said out loud rather than swallowed: a reconciliation alert with
            // nobody to send it to is the safety net not being there.
            $this->warn('No administrators to alert.');

            return;
        }

        foreach ($admins as $admin) {
            $admin->notify(new ReconciliationAlert($result));
        }

        $this->line(sprintf('  Alerted %d administrator(s).', $admins->count()));
    }
}
