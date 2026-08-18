<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue search runs on MySQL FULLTEXT in production and degrades to a
 * LIKE scan on SQLite, which is what the rest of the suite exercises. These
 * tests pin the production path itself: the index, the boolean-mode expression
 * and the prefix matching that makes "feed" find "feeder".
 *
 * They run against a real MySQL when one is reachable and skip otherwise, so a
 * laptop without MySQL still gets a green suite while CI covers the real thing.
 */
function makeVisibleProduct(array $attributes = []): Product
{
    $category = Category::factory()->create();
    $seller = SellerProfile::factory()->approved()->create();

    return Product::factory()->for($seller, 'seller')->create([
        'category_id' => $category->id,
        ...$attributes,
    ]);
}

it('creates the full-text index on MySQL', function () {
    expect(DB::connection()->getDriverName())->toBeIn(['mysql', 'mariadb']);

    $indexes = collect(DB::select('SHOW INDEX FROM products'))
        ->where('Index_type', 'FULLTEXT')
        ->pluck('Key_name')
        ->unique();

    expect($indexes)->toContain('products_search_fulltext');
});

it('uses a real full-text match rather than a LIKE scan', function () {
    $sql = Product::query()->search('mash')->toSql();

    expect($sql)->toContain('match')->toContain('boolean mode')
        ->and($sql)->not->toContain('like');
});

it('finds a listing by a whole word', function () {
    makeVisibleProduct(['name' => 'Broiler starter mash', 'description' => 'Fresh stock this week.']);
    makeVisibleProduct(['name' => 'Plasson drinker', 'description' => 'Automatic bell drinker.']);

    expect(Product::query()->visible()->search('mash')->pluck('name'))
        ->toContain('Broiler starter mash')
        ->not->toContain('Plasson drinker');
});

it('matches a prefix, so "feed" finds "feeder" and "feeds"', function () {
    makeVisibleProduct(['name' => 'Tube feeder, 15kg', 'description' => 'Galvanised tube feeder.']);
    makeVisibleProduct(['name' => 'Battery cage', 'description' => 'Ninety-six bird colony cage.']);

    // A farmer types "feed"; a bare token match would find nothing here, which
    // is the wrong answer to a perfectly reasonable search.
    expect(Product::query()->visible()->search('feed')->pluck('name'))
        ->toContain('Tube feeder, 15kg')
        ->not->toContain('Battery cage');
});

it('does not read a hyphen as an exclusion', function () {
    makeVisibleProduct(['name' => 'Day-old broiler chicks', 'description' => 'Vaccinated at the hatchery.']);

    // "-old" in boolean mode means "must not contain old". Stripping operators
    // is what stops the search returning the opposite of what was asked.
    expect(Product::query()->visible()->search('day-old')->count())->toBe(1);
});

it('requires every word rather than any of them', function () {
    makeVisibleProduct(['name' => 'Broiler starter mash', 'description' => 'Poultry feed for young birds.']);
    makeVisibleProduct(['name' => 'Layer starter crumbs', 'description' => 'Poultry feed for pullets.']);

    expect(Product::query()->visible()->search('broiler starter')->pluck('name'))
        ->toContain('Broiler starter mash')
        ->not->toContain('Layer starter crumbs');
});

it('falls back to a LIKE scan when every word is too short to be indexed', function () {
    makeVisibleProduct(['name' => 'PK 20 sprayer', 'description' => 'Knapsack sprayer.']);

    // Words below the index's token size are never matched by full-text, so a
    // pure full-text query here would confidently return nothing.
    $sql = Product::query()->search('pk')->toSql();

    expect($sql)->toContain('like')
        ->and(Product::query()->visible()->search('pk')->count())->toBe(1);
});

it('returns nothing for a search that matches nothing', function () {
    makeVisibleProduct(['name' => 'Broiler starter mash']);

    expect(Product::query()->visible()->search('zzzznotathinganybodysells')->count())->toBe(0);
});

it('survives punctuation somebody pasted in', function () {
    makeVisibleProduct(['name' => 'Broiler starter mash']);

    foreach (['+++', '"""', '((()))', '~~~', '   ', '*'] as $nonsense) {
        expect(fn (): int => Product::query()->visible()->search($nonsense)->count())
            ->not->toThrow(Throwable::class);
    }
});

it('combines search with the state filter across the join without an ambiguous column', function () {
    $oyo = SellerProfile::factory()->approved()->create(['state' => 'Oyo']);
    $category = Category::factory()->create();

    Product::factory()->for($oyo, 'seller')->create([
        'name' => 'Broiler starter mash',
        'category_id' => $category->id,
    ]);

    // Both products and seller_profiles have a `status` column; an unqualified
    // scope here is an ambiguous-column error rather than a wrong answer.
    $count = Product::query()
        ->visible()
        ->search('mash')
        ->join('seller_profiles', 'products.seller_id', '=', 'seller_profiles.id')
        ->where('seller_profiles.state', 'Oyo')
        ->count();

    expect($count)->toBe(1);
});
