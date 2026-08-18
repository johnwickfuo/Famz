<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Give a user the platform roles named. Roles are additive, so this can be
 * called with several at once.
 */
function withRoles(User $user, string ...$roles): User
{
    if (Role::query()->count() === 0) {
        (new RoleSeeder)->run();
    }

    $user->syncRoles($roles);

    return $user->fresh();
}
