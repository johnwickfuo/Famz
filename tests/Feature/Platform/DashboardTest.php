<?php

use App\Enums\RoleName;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\Platform\DashboardService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * One home page that adapts to what somebody actually does here.
 *
 * The two claims worth testing: a section appears only when it applies, and the
 * whole page stays cheap. The second is the one that decays silently — a
 * section added later with a lazy relation would still render correctly and
 * quietly cost twenty queries on mobile data.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->dashboard = app(DashboardService::class);

    $this->keys = fn (User $user): array => collect($this->dashboard->for($user)['sections'])
        ->pluck('key')
        ->all();
});

it('shows nothing but a greeting to somebody brand new', function (): void {
    $user = User::factory()->create();

    expect(($this->keys)($user))->toBe([]);
});

it('shows a seller their sales section even before their first order', function (): void {
    $user = User::factory()->create();
    $user->assignRole(RoleName::Seller->value);
    SellerProfile::factory()->create(['user_id' => $user->id]);

    // The point of showing it empty: that is where a newly approved seller
    // finds out there is nothing to do yet.
    expect(($this->keys)($user->fresh()))->toContain('selling');
});

it('does not show a buyer a seller section', function (): void {
    $user = User::factory()->create();
    Order::factory()->create(['user_id' => $user->id]);

    $keys = ($this->keys)($user->fresh());

    expect($keys)->toContain('orders')
        ->and($keys)->not->toContain('selling')
        ->and($keys)->not->toContain('jobs');
});

it('shows a worker their applications section', function (): void {
    $user = User::factory()->create();
    $user->assignRole(RoleName::Worker->value);
    WorkerProfile::factory()->create(['user_id' => $user->id]);

    expect(($this->keys)($user->fresh()))->toContain('jobs');
});

it('shows somebody who wears two hats both sections', function (): void {
    $user = User::factory()->create();
    $user->assignRole(RoleName::Seller->value);
    $user->assignRole(RoleName::Worker->value);
    SellerProfile::factory()->create(['user_id' => $user->id]);
    WorkerProfile::factory()->create(['user_id' => $user->id]);
    Order::factory()->create(['user_id' => $user->id]);

    $keys = ($this->keys)($user->fresh());

    expect($keys)->toContain('selling')
        ->and($keys)->toContain('jobs')
        ->and($keys)->toContain('orders');
});

it('caps each section at a preview rather than listing everything', function (): void {
    $user = User::factory()->create();
    Order::factory()->count(8)->create(['user_id' => $user->id]);

    $orders = collect($this->dashboard->for($user->fresh())['sections'])->firstWhere('key', 'orders');

    // This is a place to notice something needs you, not a backlog to work
    // through — every section links to the screen built for that.
    expect($orders['rows'])->toHaveCount(3);
});

it('builds the whole page in a bounded number of queries', function (): void {
    $user = User::factory()->create();
    $user->assignRole(RoleName::Seller->value);
    $user->assignRole(RoleName::Worker->value);
    SellerProfile::factory()->create(['user_id' => $user->id]);
    WorkerProfile::factory()->create(['user_id' => $user->id]);
    Order::factory()->count(5)->create(['user_id' => $user->id]);

    $user = $user->fresh();

    DB::enableQueryLog();
    $this->dashboard->for($user);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
     * A ceiling rather than an exact number, so adding a section does not fail
     * this test for no reason — but a low enough ceiling that a lazy relation
     * inside a row loop would blow straight through it.
     */
    expect($queries)->toBeLessThan(30);
});

it('renders for a signed-in user', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->has('greeting')->has('sections'));
});

it('is not reachable signed out', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
