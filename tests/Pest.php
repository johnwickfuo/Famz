<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Give a user the platform roles named. Roles are additive, so this can be
 * called with several at once.
 */
function withRoles(\App\Models\User $user, string ...$roles): \App\Models\User
{
    if (\Spatie\Permission\Models\Role::query()->count() === 0) {
        (new RoleSeeder)->run();
    }

    $user->syncRoles($roles);

    return $user->fresh();
}
