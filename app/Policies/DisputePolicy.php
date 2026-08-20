<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Dispute;
use App\Models\User;

/**
 * Who may see and answer a dispute.
 *
 * Three parties, one thread: whoever raised it, whoever it is about, and an
 * administrator. Nobody else — a dispute contains a delivery address,
 * photographs of somebody's farm, and an argument neither side wants public.
 *
 * On a mentorship dispute the two parties are the client and the mentor, and
 * the thread carries the contact details they exchanged, which makes keeping
 * it closed rather more than a courtesy.
 */
class DisputePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Dispute $dispute): bool
    {
        return $this->isParty($user, $dispute) || $user->holdsRole(RoleName::Admin);
    }

    /**
     * Only while it is live. A settled dispute is a record, not a conversation.
     */
    public function reply(User $user, Dispute $dispute): bool
    {
        return $dispute->isLive()
            && ($this->isParty($user, $dispute) || $user->holdsRole(RoleName::Admin));
    }

    /**
     * Only an administrator decides. Neither party arbitrates their own case.
     */
    public function resolve(User $user, Dispute $dispute): bool
    {
        return $user->holdsRole(RoleName::Admin) && $dispute->isLive();
    }

    public function delete(User $user, Dispute $dispute): bool
    {
        return false;
    }

    private function isParty(User $user, Dispute $dispute): bool
    {
        if ($dispute->isMentorship()) {
            $engagement = $dispute->engagement;

            return $engagement?->client_id === $user->getKey()
                || $engagement?->mentor?->user_id === $user->getKey();
        }

        if ($dispute->subOrder?->order?->user_id === $user->getKey()) {
            return true;
        }

        $seller = $user->activeSellerProfile();

        return $seller !== null && $dispute->subOrder?->seller_id === $seller->getKey();
    }
}
