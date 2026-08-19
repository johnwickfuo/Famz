<?php

namespace App\Console\Commands;

use App\Services\Mentorship\EngagementService;
use Illuminate\Console\Command;

/**
 * The clock on mentorship.
 *
 * Two things happen on their own, in this order:
 *
 *  1. An engagement the mentor marked finished, and the client has neither
 *     confirmed nor disputed inside the window, is confirmed automatically.
 *     A mentor must not be left unpaid because somebody stopped reading their
 *     email — and a client who wanted to object has had a week and a dispute
 *     route to do it through.
 *
 *  2. A running monthly or weekly engagement whose period has ended gets its
 *     next invoice, so the client is billed per confirmed period rather than
 *     upfront for the whole term.
 *
 * Auto-confirmation runs first because an engagement being confirmed ends it,
 * and an ended engagement should not then be sent another bill.
 */
class SweepMentorship extends Command
{
    protected $signature = 'mentorship:sweep';

    protected $description = 'Auto-confirm engagements nobody answered and open the next billing period';

    public function handle(EngagementService $engagements): int
    {
        $confirmed = $engagements->autoConfirmDue();
        $invoiced = $engagements->openDuePeriods();

        $this->info(sprintf(
            '%d engagement(s) auto-confirmed, %d invoice(s) opened.',
            $confirmed,
            $invoiced,
        ));

        return self::SUCCESS;
    }
}
