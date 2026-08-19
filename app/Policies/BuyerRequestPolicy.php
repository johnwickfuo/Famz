<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\BuyerRequest;
use App\Models\User;

/**
 * Who may do what with a wanted ad.
 */
class BuyerRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BuyerRequest $request): bool
    {
        return $request->status->isPublic()
            || $request->user_id === $user->getKey()
            || $user->holdsRole(RoleName::Admin);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * The author's own screen: compare offers, accept one, close early.
     */
    public function manage(User $user, BuyerRequest $request): bool
    {
        return $request->user_id === $user->getKey();
    }

    public function review(User $user, BuyerRequest $request): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    public function delete(User $user, BuyerRequest $request): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }
}
