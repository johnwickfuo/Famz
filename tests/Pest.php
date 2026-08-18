<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\MySqlTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
 * tests/Driver holds the tests that only mean something on the production
 * database driver — the FULLTEXT index and boolean-mode search above all.
 * Testing those on SQLite would only prove the fallback works. They run against
 * a real MySQL when one is reachable and skip otherwise.
 *
 * Truncation rather than RefreshDatabase, because InnoDB does not update a
 * FULLTEXT index until the transaction commits: wrapping each test in one would
 * make every search in here match nothing.
 */
pest()->extend(MySqlTestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Driver');

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
