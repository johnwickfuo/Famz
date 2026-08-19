<?php

namespace App\Console\Commands;

use App\Services\Orders\FulfilmentService;
use Illuminate\Console\Command;

/**
 * Pay sellers whose escrow window has run out.
 *
 * A buyer who never gets round to confirming receipt must not leave a seller
 * unpaid for ever, so the hold expires on its own after
 * `escrow_auto_release_days`. Anything in dispute is skipped — that is the
 * whole point of a dispute.
 */
class ReleaseDueEscrow extends Command
{
    protected $signature = 'escrow:release';

    protected $description = 'Release held funds for deliveries past their escrow window';

    public function handle(FulfilmentService $fulfilment): int
    {
        $released = $fulfilment->releaseDueEscrow();

        $this->info($released === 0
            ? 'Nothing was due for release.'
            : "Released {$released} sub-order(s) to their sellers.");

        return self::SUCCESS;
    }
}
