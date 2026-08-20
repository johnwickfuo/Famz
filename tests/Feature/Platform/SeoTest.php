<?php

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WorkerProfile;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

/**
 * What a crawler and a link preview see.
 *
 * The sitemap tests are the ones that matter. A sitemap is an invitation to
 * index, and a worker's phone number in a search engine is a disclosure outside
 * every rate limit and log the jobs module was built around — and unlike a
 * mistaken page, it cannot be taken back.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Cache::flush();
});

it('renders a title and description in the HTML itself', function (): void {
    // Server-rendered, because WhatsApp — which is how most links from this
    // platform will actually be shared — reads these and runs no JavaScript.
    $html = get(route('pages.about'))->getContent();

    expect($html)->toContain('<meta name="description"')
        ->and($html)->toContain('property="og:title"')
        ->and($html)->toContain('property="og:url"');
});

it('puts the page name in front of the company name', function (): void {
    $html = get(route('pages.faq'))->getContent();

    // The page name, a separator, then the platform name. A shared link
    // carrying only the platform name tells nobody which page it is.
    expect($html)->toMatch('/<title[^>]*>Common questions · /');
});

it('describes a product with its own text and image', function (): void {
    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);
    $seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $product = Product::factory()->for($seller, 'seller')->create([
        'name' => 'Broiler starter mash',
        'description' => 'Twenty-five kilogramme bags of starter feed, milled weekly.',
        'status' => ProductStatus::Active,
    ]);

    $html = get(route('catalogue.product', $product))->getContent();

    expect($html)->toContain('Broiler starter mash')
        ->and($html)->toContain('Twenty-five kilogramme bags');
});

it('trims a description to what a search result actually shows', function (): void {
    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);
    $seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $product = Product::factory()->for($seller, 'seller')->create([
        'name' => 'Long described feed',
        'description' => str_repeat('This is a very long description. ', 40),
        'status' => ProductStatus::Active,
    ]);

    $html = get(route('catalogue.product', $product))->getContent();

    preg_match('/<meta name="description" content="([^"]*)"/', $html, $matches);

    // 160 plus the ellipsis Str::limit adds. Longer is not more information,
    // it is the same information with the end cut off by somebody else.
    expect(mb_strlen($matches[1] ?? ''))->toBeLessThanOrEqual(163);
});

it('tells a crawler to stay away from a worker profile', function (): void {
    $employerUser = User::factory()->create();
    $employerUser->assignRole(RoleName::Employer->value);
    \App\Models\EmployerProfile::factory()->create(['user_id' => $employerUser->id]);

    $workerUser = User::factory()->create();
    $workerUser->assignRole(RoleName::Worker->value);
    $worker = WorkerProfile::factory()->create(['user_id' => $workerUser->id]);

    $html = $this->actingAs($employerUser)
        ->get(route('jobs.workers.show', $worker))
        ->getContent();

    expect($html)->toContain('name="robots" content="noindex, nofollow"');
});

it('leaves an ordinary page indexable', function (): void {
    expect(get(route('pages.about'))->getContent())
        ->not->toContain('content="noindex');
});

it('serves a sitemap', function (): void {
    get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
});

it('keeps the worker directory out of the sitemap', function (): void {
    $workerUser = User::factory()->create();
    $workerUser->assignRole(RoleName::Worker->value);
    $worker = WorkerProfile::factory()->create([
        'user_id' => $workerUser->id,
        'phone' => '08031234567',
    ]);

    $xml = get('/sitemap.xml')->getContent();

    /*
     * The rule the whole file is shaped around. Indexing a page carrying a
     * phone number puts that number in a search engine forever.
     */
    expect($xml)->not->toContain('/jobs/workers')
        ->and($xml)->not->toContain('08031234567');
});

it('keeps mentor profiles out of the sitemap', function (): void {
    expect(get('/sitemap.xml')->getContent())->not->toContain('/mentors/');
});

it('lists the public pages a crawler should find', function (): void {
    $xml = get('/sitemap.xml')->getContent();

    expect($xml)->toContain(route('catalogue.home'))
        ->and($xml)->toContain(route('pages.terms'))
        ->and($xml)->toContain(route('pages.how-it-works'))
        ->and($xml)->toContain(route('assistant.show'));
});

it('lists an approved product and leaves a pending one out', function (): void {
    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);
    $seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $live = Product::factory()->for($seller, 'seller')->create(['status' => ProductStatus::Active]);
    $pending = Product::factory()->for($seller, 'seller')->create(['status' => ProductStatus::PendingReview]);

    $xml = get('/sitemap.xml')->getContent();

    expect($xml)->toContain(route('catalogue.product', $live))
        ->and($xml)->not->toContain(route('catalogue.product', $pending));
});

it('blocks the sensitive routes in robots.txt', function (): void {
    $robots = get('/robots.txt')->assertOk()->getContent();

    expect($robots)
        // The brief names both of these specifically.
        ->toContain('Disallow: /mentors/join')
        ->toContain('Disallow: /jobs/workers')
        // Absolute, which is why this is served from a route rather than as a
        // static file — a relative Sitemap directive is rejected as invalid.
        ->toContain('Sitemap: '.route('sitemap'));
});

it('serves robots.txt as plain text', function (): void {
    get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
});
