<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\SellerProfile;
use App\Models\User;

class SellerProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    public function view(User $user, SellerProfile $seller): bool
    {
        return $user->holdsRole(RoleName::Admin) || $seller->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        // One application per account, and not while one is already open.
        return $user->sellerProfile === null;
    }

    public function update(User $user, SellerProfile $seller): bool
    {
        if ($user->holdsRole(RoleName::Admin)) {
            return true;
        }

        // The applicant may keep editing while the application is still open —
        // which is what "request more information" depends on.
        return $seller->user_id === $user->getKey() && $seller->status->isOpenToApplicant();
    }

    public function delete(User $user, SellerProfile $seller): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    /**
     * Approving, rejecting or asking for more information.
     */
    public function review(User $user, SellerProfile $seller): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }
}
