<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    public function view(User $user, Category $category): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    public function create(User $user): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    public function update(User $user, Category $category): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }
}
