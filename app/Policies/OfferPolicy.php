<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Offer;
use App\Models\User;

/**
 * Who may see and answer an offer.
 *
 * Two people and an administrator. A third party reading somebody's offer
 * would learn what a competitor bid, which is the one thing a sealed board
 * exists to prevent.
 */
class OfferPolicy
{
    public function view(User $user, Offer $offer): bool
    {
        return $this->isParty($user, $offer) || $user->holdsRole(RoleName::Admin);
    }

    /**
     * Only the person the offer is waiting on may answer it.
     */
    public function respond(User $user, Offer $offer): bool
    {
        return $user->getKey() === $offer->responder_id && $offer->isOpen();
    }

    /**
     * Only whoever made an offer may take it back, and only before an answer.
     */
    public function withdraw(User $user, Offer $offer): bool
    {
        return $user->getKey() === $offer->initiator_id && $offer->isOpen();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Offer $offer): bool
    {
        // Offers are the record of a negotiation; they are answered, never
        // removed.
        return false;
    }

    private function isParty(User $user, Offer $offer): bool
    {
        return in_array($user->getKey(), [$offer->initiator_id, $offer->responder_id], true);
    }
}
