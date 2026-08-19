<?php

namespace App\Console\Commands;

use App\Services\Offers\BuyerRequestService;
use App\Services\Offers\NegotiatedCheckout;
use App\Services\Offers\OfferService;
use Illuminate\Console\Command;

/**
 * The clock on the offer engine.
 *
 * Four things run out on their own: offers nobody answered, wanted ads nobody
 * filled, the warning that an ad is about to close, and stock held for a buyer
 * who never came back. Doing them in one command keeps the ordering right —
 * requests expire before the offers standing on them are swept, so a seller is
 * told the request closed rather than that their offer merely lapsed.
 */
class SweepOffers extends Command
{
    protected $signature = 'offers:sweep';

    protected $description = 'Expire offers and requests that have run out, and free reserved stock';

    public function handle(
        OfferService $offers,
        BuyerRequestService $requests,
        NegotiatedCheckout $checkout,
    ): int {
        $requestResult = $requests->sweep();
        $expiredOffers = $offers->expireDue();
        $released = $checkout->releaseLapsedReservations();

        $this->info(sprintf(
            '%d request(s) closed, %d buyer(s) warned, %d offer(s) lapsed, %d reservation(s) released.',
            $requestResult['expired'],
            $requestResult['warned'],
            $expiredOffers,
            $released,
        ));

        return self::SUCCESS;
    }
}
