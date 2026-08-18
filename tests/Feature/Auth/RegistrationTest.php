<?php

use App\Enums\UserStatus;
use App\Mail\WelcomeMail;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

test('registration screen can be rendered', function () {
    $this->get('/register')->assertOk();
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Aisha Bello',
        'email' => 'aisha@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

it('creates a plain user with no elevated role', function () {
    $this->seed(RoleSeeder::class);

    $this->post('/register', [
        'name' => 'Aisha Bello',
        'email' => 'aisha@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'aisha@example.test')->firstOrFail();

    expect($user->getRoleNames())->toBeEmpty()
        ->and($user->status)->toBe(UserStatus::Pending);
});

it('creates the profile row alongside the user', function () {
    $this->post('/register', [
        'name' => 'Aisha Bello',
        'email' => 'aisha@example.test',
        'phone' => '08030000000',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'aisha@example.test')->firstOrFail();

    expect($user->profile)->not->toBeNull()
        ->and($user->profile->display_name)->toBe('Aisha Bello')
        ->and($user->profile->phone)->toBe('08030000000')
        ->and($user->displayName())->toBe('Aisha Bello');
});

it('asks the new user to verify their email', function () {
    Event::fake([Registered::class]);

    $this->post('/register', [
        'name' => 'Aisha Bello',
        'email' => 'aisha@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    Event::assertDispatched(Registered::class);

    $user = User::where('email', 'aisha@example.test')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();
});

it('rolls the whole registration back if the profile cannot be written', function () {
    // Registration writes the user and the profile in one transaction, so a
    // half-registered account can never exist.
    Schema::drop('profiles');

    try {
        $this->post('/register', [
            'name' => 'Aisha Bello',
            'email' => 'aisha@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    } catch (Throwable) {
        // The failure itself is not what is under test.
    }

    expect(User::where('email', 'aisha@example.test')->exists())->toBeFalse();
});

it('rejects a duplicate email address', function () {
    User::factory()->create(['email' => 'aisha@example.test']);

    $this->post('/register', [
        'name' => 'Someone Else',
        'email' => 'aisha@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('sends the welcome mail through the queue, never inline', function () {
    expect(new WelcomeMail(User::factory()->create()))
        ->toBeInstanceOf(ShouldQueue::class);
});
