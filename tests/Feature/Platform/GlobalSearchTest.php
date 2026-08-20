<?php

use App\Enums\CourseStatus;
use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Models\Course;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\Platform\GlobalSearch;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

use function Pest\Laravel\get;

/**
 * One box, five kinds of answer — and one kind it must never give.
 *
 * The scoping tests here are not paranoia. Search touches every table and its
 * results are a list of fragments nobody looks at closely, which makes it the
 * easiest place in an application to leak a draft, an unapproved listing, or a
 * phone number the jobs module spent a whole phase protecting.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->search = app(GlobalSearch::class);

    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);
    $this->seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $this->titles = fn (array $results, string $key): array => collect($results['groups'])
        ->firstWhere('key', $key)['rows'] ?? [];
});

it('finds a product across the platform', function (): void {
    Product::factory()->for($this->seller, 'seller')->create([
        'name' => 'Broiler starter mash',
        'status' => ProductStatus::Active,
    ]);

    $results = $this->search->search('broiler');

    expect(collect($results['groups'])->pluck('key'))->toContain('products')
        ->and(collect(($this->titles)($results, 'products'))->pluck('title'))
        ->toContain('Broiler starter mash');
});

it('never returns a worker profile', function (): void {
    $workerUser = User::factory()->create(['name' => 'Broiler Specialist']);
    $workerUser->assignRole(RoleName::Worker->value);

    WorkerProfile::factory()->create([
        'user_id' => $workerUser->id,
        'full_name' => 'Broiler Specialist',
        'phone' => '08030000000',
    ]);

    $results = $this->search->search('broiler');

    /*
     * The rule the jobs module was built around. A worker's phone is released
     * to a registered employer, through a rate limit, with the view logged —
     * and a public search box that returned worker rows would be the way
     * around all three.
     */
    expect(collect($results['groups'])->pluck('key'))->not->toContain('workers');

    $encoded = json_encode($results);

    expect($encoded)->not->toContain('08030000000')
        ->and($encoded)->not->toContain('Broiler Specialist');
});

it('leaves an unapproved listing out', function (): void {
    Product::factory()->for($this->seller, 'seller')->create([
        'name' => 'Pending broiler feed',
        'status' => ProductStatus::PendingReview,
    ]);

    expect(json_encode($this->search->search('broiler')))->not->toContain('Pending broiler feed');
});

it('leaves an unpublished course out', function (): void {
    Course::factory()->create(['title' => 'Draft broiler course', 'status' => CourseStatus::Draft]);
    Course::factory()->create(['title' => 'Live broiler course', 'status' => CourseStatus::Published]);

    $encoded = json_encode($this->search->search('broiler'));

    expect($encoded)->toContain('Live broiler course')
        ->and($encoded)->not->toContain('Draft broiler course');
});

it('drops a group with no matches rather than showing five empty headings', function (): void {
    Product::factory()->for($this->seller, 'seller')->create([
        'name' => 'Broiler starter mash',
        'status' => ProductStatus::Active,
    ]);

    $results = $this->search->search('broiler');

    expect(collect($results['groups'])->pluck('key')->all())->toBe(['products']);
});

it('refuses a term too short to mean anything', function (): void {
    expect($this->search->search('b'))
        ->toHaveKey('tooShort', true)
        ->and($this->search->search('b')['groups'])->toBe([]);
});

it('reports the real total, not the size of the preview', function (): void {
    Product::factory()->count(9)->for($this->seller, 'seller')->create([
        'name' => 'Broiler feed',
        'status' => ProductStatus::Active,
    ]);

    $results = $this->search->search('broiler');
    $products = collect($results['groups'])->firstWhere('key', 'products');

    // Six shown, nine found — "6 of 6" on every search would be a lie that
    // hides the See-all link.
    expect($products['rows'])->toHaveCount(6)
        ->and($products['total'])->toBe(9);
});

it('narrows to one type when asked', function (): void {
    Product::factory()->for($this->seller, 'seller')->create([
        'name' => 'Broiler starter mash',
        'status' => ProductStatus::Active,
    ]);
    Course::factory()->create(['title' => 'Broiler basics', 'status' => CourseStatus::Published]);

    $results = $this->search->search('broiler', ['courses']);

    expect(collect($results['groups'])->pluck('key')->all())->toBe(['courses']);
});

it('ignores a type nobody asked for', function (): void {
    // A crafted ?type= must not widen the search into something not on the list.
    expect($this->search->search('broiler', ['workers'])['groups'])->toBe([]);
});

it('is open to anybody, signed in or not', function (): void {
    get(route('search', ['q' => 'broiler']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Search/Index')->has('groups'));
});

it('rejects a type outside the list at the request', function (): void {
    get(route('search', ['q' => 'broiler', 'type' => 'workers']))
        ->assertSessionHasErrors('type');
});
