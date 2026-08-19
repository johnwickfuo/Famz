<?php

namespace App\Notifications;

use App\Models\BuyerRequest;
use App\Models\Offer;
use App\Models\Product;

/**
 * Where a notification about an offer should take somebody.
 *
 * The same offer belongs on two different screens depending on who is reading:
 * a seller answers it in their panel, a buyer sees it on the request or the
 * listing. Working that out in each notification separately is how one of them
 * ends up pointing at a page the reader cannot open.
 */
class OfferLinks
{
    public static function for(Offer $offer, ?object $notifiable = null): string
    {
        $offerable = $offer->offerable;

        // The seller's side of a listing negotiation is worked in the panel.
        if ($offerable instanceof Product) {
            $isSeller = $notifiable !== null
                && $offerable->seller?->user_id === ($notifiable->getKey() ?? null);

            return $isSeller
                ? url('/seller/offers')
                : route('catalogue.product', $offerable->slug);
        }

        if ($offerable instanceof BuyerRequest) {
            $isBuyer = $notifiable !== null && $offerable->user_id === ($notifiable->getKey() ?? null);

            return $isBuyer
                ? route('requests.manage', $offerable->slug)
                : route('requests.show', $offerable->slug);
        }

        return url('/');
    }
}
