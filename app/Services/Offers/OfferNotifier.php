<?php

namespace App\Services\Offers;

use App\Models\BuyerRequest;
use App\Models\NegotiatedPurchase;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Notifications\BuyerRequestExpiring;
use App\Notifications\BuyerRequestReviewed;
use App\Notifications\OfferAccepted;
use App\Notifications\OfferCountered;
use App\Notifications\OfferReceived;
use App\Notifications\OfferRejected;

/**
 * Who gets told what, and when.
 *
 * Kept out of OfferService so the rules about *who* hears about a state change
 * are in one readable place, rather than scattered through the rules about
 * what a state change means.
 */
class OfferNotifier
{
    public function offerReceived(Offer $offer): void
    {
        $offer->responder?->notify(new OfferReceived($offer));
    }

    public function offerCountered(Offer $counter): void
    {
        $counter->responder?->notify(new OfferCountered($counter));
    }

    /**
     * Both sides hear about an acceptance, and they hear different things: the
     * buyer gets a link to pay, the seller gets told to expect the money.
     */
    public function offerAccepted(Offer $offer, ?NegotiatedPurchase $purchase = null): void
    {
        $buyer = $this->buyerOf($offer);
        $seller = $this->sellerUserOf($offer);

        $buyer?->notify(new OfferAccepted($offer, $purchase, forBuyer: true));

        if ($seller !== null && ! $seller->is($buyer)) {
            $seller->notify(new OfferAccepted($offer, $purchase, forBuyer: false));
        }
    }

    public function offerRejected(Offer $offer, ?string $reason = null): void
    {
        $offer->initiator?->notify(new OfferRejected($offer, $reason));
    }

    public function requestReviewed(BuyerRequest $request, bool $approved): void
    {
        $request->buyer?->notify(new BuyerRequestReviewed($request, $approved));
    }

    public function requestExpiring(BuyerRequest $request, int $daysLeft): void
    {
        $request->buyer?->notify(new BuyerRequestExpiring($request, $daysLeft));
    }

    /**
     * Whoever ends up paying.
     *
     * On a listing that is whoever made the offer; on a wanted ad it is the
     * person who posted it, whichever way round the last counter went.
     */
    private function buyerOf(Offer $offer): ?User
    {
        $offerable = $offer->offerable;

        if ($offerable instanceof BuyerRequest) {
            return $offerable->buyer;
        }

        return $offer->seller?->user_id === $offer->initiator_id
            ? $offer->responder
            : $offer->initiator;
    }

    private function sellerUserOf(Offer $offer): ?User
    {
        if ($offer->seller !== null) {
            return $offer->seller->user;
        }

        return $offer->offerable instanceof Product
            ? $offer->offerable->seller?->user
            : null;
    }
}
