<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

it('signs a suspended account straight back out', function () {
    $user = User::factory()->suspended()->create();

    // The credentials are right; the account is not usable.
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('lets a pending account sign in and use the site', function () {
    // Pending only means no elevated role has been granted yet.
    $user = User::factory()->pending()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('shares the signed-in user and their roles with every page', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->syncRoles(['seller', 'mentor']);

    $content = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

    expect($content)->toContain('seller')->toContain('mentor');
});
