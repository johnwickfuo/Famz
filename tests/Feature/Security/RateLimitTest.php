<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\post;

/**
 * The limits that stop a script, and the one endpoint that must never be limited.
 *
 * The last test in this file is the important one. Every other limiter here
 * protects the platform from somebody; the webhook exemption protects the
 * platform from itself — a 429 to a payment provider is a payment that appears
 * not to have been confirmed, and the provider will stop retrying long before
 * anybody notices the money is missing.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Cache::flush();
    RateLimiter::clear('');
});

it('registers every named limiter the routes ask for', function (string $limiter): void {
    // A route referencing a limiter that was never defined throws at request
    // time, not at boot — so this is the check that a rename does not ship.
    expect(RateLimiter::limiter($limiter))->not->toBeNull();
})->with(['auth', 'offers', 'assistant', 'forms', 'checkout', 'polling']);

it('throttles repeated sign-in attempts', function (): void {
    foreach (range(1, 12) as $ignored) {
        $response = post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
    }

    expect($response->status())->toBe(429);
});

it('throttles the contact form', function (): void {
    $payload = [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'subject' => 'Hello',
        'message' => 'A message of sufficient length to pass validation.',
    ];

    foreach (range(1, 10) as $ignored) {
        $response = post(route('contact.store'), $payload);
    }

    expect($response->status())->toBe(429);
});

it('throttles the assistant', function (): void {
    foreach (range(1, 25) as $ignored) {
        $response = $this->postJson(route('assistant.ask'), ['message' => 'how much feed']);
    }

    expect($response->status())->toBe(429);
});

it('throttles consultation bookings', function (): void {
    foreach (range(1, 10) as $ignored) {
        $response = post(route('consultations.store'), []);
    }

    // 429 rather than 422: the limiter runs before validation, which is the
    // point — a script posting rubbish should be stopped, not validated.
    expect($response->status())->toBe(429);
});

it('counts a signed-in user separately from their address', function (): void {
    /*
     * The reason limiters key on the account first. An address is shared — an
     * office, a campus, a carrier's NAT — so keying on it alone would let one
     * busy seller lock out everybody on the same network.
     */
    $first = User::factory()->create(['email_verified_at' => now()]);
    $second = User::factory()->create(['email_verified_at' => now()]);

    foreach (range(1, 8) as $ignored) {
        $this->actingAs($first)->postJson(route('assistant.ask'), ['message' => 'a question']);
    }

    // Same address, different account: unaffected.
    $response = $this->actingAs($second)->postJson(route('assistant.ask'), ['message' => 'a question']);

    expect($response->status())->not->toBe(429);
});

it('leaves payment webhooks unthrottled', function (): void {
    /*
     * Deliberate, and the most important line in this file.
     *
     * Paystack and Flutterwave retry a failed delivery a fixed number of times
     * and then give up. A 429 spends one of those retries on a response that
     * says nothing about whether the payment was recorded — and when the
     * retries run out, a paid order stays unpaid with no trace of why.
     */
    $webhookRoutes = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'webhook'));

    expect($webhookRoutes)->not->toBeEmpty();

    foreach ($webhookRoutes as $route) {
        $throttled = collect($route->gatherMiddleware())
            ->contains(fn ($m): bool => str_starts_with((string) $m, 'throttle'));

        expect($throttled)->toBeFalse($route->uri());
    }
});

it('throttles every public form endpoint', function (): void {
    /*
     * Checked by walking the route table rather than by listing routes here:
     * a form added next month is a form somebody will forget to limit, and
     * this fails when that happens rather than after the abuse.
     */
    $unlimited = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->filter(fn ($route): bool => in_array('POST', $route->methods(), true))
        ->filter(fn ($route): bool => ! str_contains($route->uri(), 'webhook'))
        ->filter(fn ($route): bool => ! str_starts_with($route->uri(), 'admin'))
        ->filter(fn ($route): bool => ! str_starts_with($route->uri(), 'seller'))
        ->filter(fn ($route): bool => ! str_starts_with($route->uri(), 'mentor'))
        ->filter(fn ($route): bool => ! str_contains($route->uri(), 'livewire'))
        // Endpoints that create nothing and cost nothing: signing out, marking
        // a notification read, updating a cart line.
        ->reject(fn ($route): bool => in_array($route->getName(), [
            'logout', 'notifications.readAll', 'cart.store', 'cart.update', 'cart.destroy',
            'account.notifications.update', 'account.sessions.destroy', 'profile.update',
            'password.update', 'password.confirm', 'verification.send',
        ], true))
        ->reject(fn ($route): bool => collect($route->gatherMiddleware())
            ->contains(fn ($m): bool => str_starts_with((string) $m, 'throttle')))
        ->map(fn ($route): string => $route->getName() ?? $route->uri())
        ->values();

    expect($unlimited->all())->toBe([]);
});
