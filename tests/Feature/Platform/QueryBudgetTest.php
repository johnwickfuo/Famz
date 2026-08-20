<?php

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Models\Category;
use App\Models\Course;
use App\Models\JobListing;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

/**
 * A query budget on the pages a farmer actually loads on mobile data.
 *
 * These are ceilings, not exact counts — a page that gains a feature should not
 * fail this for no reason. But they are tight enough that the thing they exist
 * to catch does fail them: a relation loaded inside a loop, where the query
 * count grows with the number of rows on the page.
 *
 * That is why every test here creates MORE rows than a preview shows. Ten
 * products under one budget proves eager loading; one product proves nothing at
 * all, because N+1 and a single query look identical when N is one.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Cache::flush();

    // Faker's unique() pool is per-process, so a file of tests that each make
    // rows can exhaust it partway through and fail on the generator rather
    // than on anything being tested.
    fake()->unique(true);

    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);
    $this->seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    /**
     * Count the queries one request actually makes.
     */
    $this->queriesFor = function (string $url): int {
        // Warm anything lazily built on first touch, so the number measured is
        // a steady-state page load rather than a cold boot.
        get($url);

        DB::enableQueryLog();
        DB::flushQueryLog();

        get($url);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
});

it('loads the catalogue home within budget', function (): void {
    Category::factory()->count(6)->create(['is_active' => true]);
    Product::factory()->count(15)->for($this->seller, 'seller')->create([
        'status' => ProductStatus::Active,
    ]);

    // Both blocks on this page are cached, so a warm load should barely touch
    // the database at all.
    expect(($this->queriesFor)(route('catalogue.home')))->toBeLessThan(15);
});

it('loads a product page within budget', function (): void {
    $product = Product::factory()->for($this->seller, 'seller')->create([
        'status' => ProductStatus::Active,
    ]);

    expect(($this->queriesFor)(route('catalogue.product', $product)))->toBeLessThan(25);
});

it('loads the job board within budget with many listings', function (): void {
    $employerUser = User::factory()->create();
    $employerUser->assignRole(RoleName::Employer->value);
    $employer = \App\Models\EmployerProfile::factory()->create(['user_id' => $employerUser->id]);

    JobListing::factory()->count(12)->create([
        'employer_profile_id' => $employer->id,
        'status' => \App\Enums\JobListingStatus::Open,
        'application_deadline' => now()->addMonth(),
    ]);

    expect(($this->queriesFor)(route('jobs.index')))->toBeLessThan(25);
});

it('loads the course catalogue within budget', function (): void {
    /*
     * One category, ten courses — which is what real data looks like, and also
     * avoids CourseCategoryFactory's unique()->randomElement over a six-item
     * list, which exhausts on the seventh course and fails as an opaque Faker
     * error rather than anything to do with queries.
     */
    $category = \App\Models\CourseCategory::factory()->create();

    foreach (range(1, 10) as $index) {
        Course::factory()->create([
            'course_category_id' => $category->id,
            'status' => \App\Enums\CourseStatus::Published,
            'title' => "Budget course {$index}",
            'slug' => "budget-course-{$index}",
        ]);
    }

    expect(($this->queriesFor)(route('academy.catalogue')))->toBeLessThan(25);
});

it('does not grow its query count as listings are added', function (): void {
    /*
     * The N+1 test proper. Everything above is a ceiling somebody could raise;
     * this compares two page loads that differ only in how much is on them, and
     * a relation loaded inside a row loop cannot pass it.
     */
    Product::factory()->count(3)->for($this->seller, 'seller')->create([
        'status' => ProductStatus::Active,
    ]);

    $withThree = ($this->queriesFor)(route('catalogue.search'));

    Product::factory()->count(20)->for($this->seller, 'seller')->create([
        'status' => ProductStatus::Active,
    ]);

    Cache::flush();

    $withTwentyThree = ($this->queriesFor)(route('catalogue.search'));

    // A little slack for pagination arithmetic; nothing like twenty.
    expect($withTwentyThree)->toBeLessThanOrEqual($withThree + 2);
});

it('does not grow its query count as job listings are added', function (): void {
    $employerUser = User::factory()->create();
    $employerUser->assignRole(RoleName::Employer->value);
    $employer = \App\Models\EmployerProfile::factory()->create(['user_id' => $employerUser->id]);

    $make = fn (int $count) => JobListing::factory()->count($count)->create([
        'employer_profile_id' => $employer->id,
        'status' => \App\Enums\JobListingStatus::Open,
        'application_deadline' => now()->addMonth(),
    ]);

    $make(3);
    $withThree = ($this->queriesFor)(route('jobs.index'));

    $make(20);
    Cache::flush();
    $withTwentyThree = ($this->queriesFor)(route('jobs.index'));

    expect($withTwentyThree)->toBeLessThanOrEqual($withThree + 2);
});

it('serves the catalogue home from cache once it is warm', function (): void {
    Product::factory()->count(5)->for($this->seller, 'seller')->create([
        'status' => ProductStatus::Active,
    ]);

    get(route('catalogue.home'));

    DB::enableQueryLog();
    DB::flushQueryLog();
    get(route('catalogue.home'));
    $warm = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // The two expensive blocks — the category tree and the newest listings —
    // must not be queried again on a warm cache.
    expect($warm->filter(fn (string $q): bool => str_contains($q, 'from "products"'))->count())->toBe(0);
});

it('rebuilds the cache when a listing is approved', function (): void {
    get(route('catalogue.home'));

    $product = Product::factory()->for($this->seller, 'seller')->create([
        'status' => ProductStatus::Active,
        'name' => 'Freshly approved feed',
    ]);

    /*
     * The bust is the whole reason this cache is acceptable. A seller whose
     * listing goes live should see it, and "within the hour" is not an answer
     * that satisfies anybody.
     */
    $newest = get(route('catalogue.home'))->viewData('page')['props']['newestProducts'];

    expect(collect($newest)->pluck('name'))->toContain('Freshly approved feed');
});
