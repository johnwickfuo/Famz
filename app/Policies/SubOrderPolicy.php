<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\SubOrder;
use App\Models\User;

/**
 * Ownership is enforced twice, as it is on listings: this policy, and the
 * `forSeller` scope on the Filament resource. Either alone would do on a good
 * day; both together mean a forgotten scope cannot leak another seller's
 * orders, and a forgotten scope cannot be the only thing standing between a
 * seller and somebody else's money.
 */
class SubOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsRole(RoleName::Admin) || $user->activeSellerProfile() !== null;
    }

    public function view(User $user, SubOrder $subOrder): bool
    {
        return $this->owns($user, $subOrder)
            || $this->bought($user, $subOrder)
            || $user->holdsRole(RoleName::Admin);
    }

    /**
     * Sub-orders are created by the checkout, never by hand.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Accept, reject, ship, deliver — everything the seller does to their part
     * of an order.
     */
    public function fulfil(User $user, SubOrder $subOrder): bool
    {
        return $this->owns($user, $subOrder);
    }

    /**
     * Only the buyer confirms receipt. A seller releasing their own escrow
     * would make the escrow decorative.
     */
    public function markReceived(User $user, SubOrder $subOrder): bool
    {
        return $this->bought($user, $subOrder);
    }

    public function update(User $user, SubOrder $subOrder): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    public function delete(User $user, SubOrder $subOrder): bool
    {
        // Nothing in the money layer is deleted; it is reversed, and the
        // reversal is part of the record.
        return false;
    }

    private function owns(User $user, SubOrder $subOrder): bool
    {
        $seller = $user->activeSellerProfile();

        return $seller !== null && $subOrder->seller_id === $seller->getKey();
    }

    private function bought(User $user, SubOrder $subOrder): bool
    {
        return $subOrder->order?->user_id === $user->getKey();
    }
}
