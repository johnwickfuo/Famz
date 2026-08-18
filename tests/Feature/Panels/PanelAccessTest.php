<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Each panel is gated by the role whose name matches its id, enforced twice:
 * by User::canAccessPanel() and by the EnsurePanelRole middleware.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

dataset('panels', [
    'admin' => ['/admin', RoleName::Admin],
    'seller' => ['/seller', RoleName::Seller],
    'mentor' => ['/mentor', RoleName::Mentor],
]);

it('lets a user with the matching role into the panel', function (string $path, RoleName $role) {
    $user = User::factory()->create();
    $user->assignRole($role->value);

    $this->actingAs($user)->get($path)->assertOk();
})->with('panels');

it('keeps a user without the matching role out of the panel', function (string $path, RoleName $role) {
    $user = User::factory()->create();

    $this->actingAs($user)->get($path)->assertForbidden();
})->with('panels');

it('keeps a user holding only another panel role out', function (string $path, RoleName $role) {
    $other = collect(RoleName::cases())
        ->first(fn (RoleName $candidate): bool => $candidate !== $role);

    $user = User::factory()->create();
    $user->assignRole($other->value);

    $this->actingAs($user)->get($path)->assertForbidden();
})->with('panels');

it('redirects a guest to the panel login screen', function (string $path, RoleName $role) {
    $this->get($path)->assertRedirect($path.'/login');
})->with('panels');

it('keeps a suspended user out even when they hold the role', function (string $path, RoleName $role) {
    $user = User::factory()->suspended()->create();
    $user->assignRole($role->value);

    $this->actingAs($user)->get($path)->assertForbidden();
})->with('panels');

it('lets one user into several panels at once, because roles are additive', function () {
    $user = User::factory()->create();
    $user->syncRoles([RoleName::Admin->value, RoleName::Seller->value, RoleName::Mentor->value]);

    $this->actingAs($user)->get('/admin')->assertOk();
    $this->actingAs($user)->get('/seller')->assertOk();
    $this->actingAs($user)->get('/mentor')->assertOk();
});

it('does not give a worker or an employer a panel', function () {
    $user = User::factory()->create();
    $user->syncRoles([RoleName::Worker->value, RoleName::Employer->value]);

    $this->actingAs($user)->get('/admin')->assertForbidden();
    $this->actingAs($user)->get('/seller')->assertForbidden();
    $this->actingAs($user)->get('/mentor')->assertForbidden();
});
