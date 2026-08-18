<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Product;
use App\Models\User;

/**
 * Ownership is enforced twice: this policy, and the query scopes on the
 * Filament resource. Either one alone would be enough on a good day; both
 * together mean a forgotten scope cannot leak another seller's records, and a
 * forgotten policy check cannot either.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsRole(RoleName::Admin) || $user->activeSellerProfile() !== null;
    }

    public function view(User $user, Product $product): bool
    {
        return $this->owns($user, $product) || $user->holdsRole(RoleName::Admin);
    }

    public function create(User $user): bool
    {
        // Only an approved seller may list. A pending or rejected application
        // gets no further than the form.
        return $user->activeSellerProfile() !== null || $user->holdsRole(RoleName::Admin);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product) || $user->holdsRole(RoleName::Admin);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->owns($user, $product) || $user->holdsRole(RoleName::Admin);
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->delete($user, $product);
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    /**
     * Only an administrator decides whether a listing goes live.
     */
    public function review(User $user, Product $product): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    private function owns(User $user, Product $product): bool
    {
        $seller = $user->activeSellerProfile();

        return $seller !== null && $seller->getKey() === $product->seller_id;
    }
}
