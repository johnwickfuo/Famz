<?php

namespace App\Console\Commands;

use App\Services\Quotations\QuotationNotifier;
use App\Services\Quotations\QuotationService;
use Illuminate\Console\Command;

/**
 * The clock on proposals.
 *
 * A proposal that nobody withdrew is a proposal the company is still standing
 * behind — at last quarter's cement price. Nigerian input costs move fast
 * enough that this is a real exposure rather than tidiness: galvanised sheet
 * and feed can move double figures in a month, and a client who rings up in
 * June holding a March quotation is holding a number the company would lose
 * money honouring.
 *
 * Both sides are told. The client needs to know before they ring quoting it;
 * the company needs to know because a lapsed proposal is a warm lead going
 * cold, and that is worth somebody picking up the phone about.
 */
class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Mark proposals past their validity date and tell both sides';

    public function handle(QuotationService $quotations, QuotationNotifier $notifier): int
    {
        $lapsed = $quotations->expireLapsed();

        foreach ($lapsed as $quotation) {
            $notifier->quotationExpired($quotation);
        }

        $this->info(sprintf('%d proposal(s) lapsed.', count($lapsed)));

        return self::SUCCESS;
    }
}
