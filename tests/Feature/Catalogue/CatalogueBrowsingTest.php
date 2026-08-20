<?php

use App\Enums\ProductCondition;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use Database\Seeders\CategorySeeder;

beforeEach(function (): void {
    $this->seed(CategorySeeder::class);

    $this->poultry = Category::where('slug', 'poultry')->firstOrFail();
    $this->chicks = Category::where('slug', 'day-old-chicks')->firstOrFail();
    $this->tools = Category::where('slug', 'hand-tools')->firstOrFail();

    $this->oyoSeller = SellerProfile::factory()->approved()->create(['state' => 'Oyo']);
    $this->kanoSeller = SellerProfile::factory()->approved()->create(['state' => 'Kano']);
});

/**
 * @return array<int, string>
 */
function namesOn(string $url, $test): array
{
    return collect($test->get($url)->assertOk()->viewData('page')['props']['products'] ?? [])
        ->pluck('name')
        ->all();
}

// ---------------------------------------------------------------------------
// Visibility
// ---------------------------------------------------------------------------

it('shows only listings a shopper is allowed to see', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Live listing', 'category_id' => $this->chicks->id]);
    Product::factory()->draft()->for($this->oyoSeller, 'seller')->create(['name' => 'Draft listing', 'category_id' => $this->chicks->id]);
    Product::factory()->pendingReview()->for($this->oyoSeller, 'seller')->create(['name' => 'Queued listing', 'category_id' => $this->chicks->id]);
    Product::factory()->rejected()->for($this->oyoSeller, 'seller')->create(['name' => 'Rejected listing', 'category_id' => $this->chicks->id]);
    Product::factory()->outOfStock()->for($this->oyoSeller, 'seller')->create(['name' => 'Sold out listing', 'category_id' => $this->chicks->id]);

    $names = namesOn(route('catalogue.category', $this->chicks->slug), $this);

    // Out of stock stays visible: a farmer still wants to know who carries it.
    expect($names)->toContain('Live listing', 'Sold out listing')
        ->not->toContain('Draft listing', 'Queued listing', 'Rejected listing');
});

it('hides the listings of a seller who is no longer approved', function () {
    $seller = SellerProfile::factory()->create(['state' => 'Ogun']);
    Product::factory()->for($seller, 'seller')->create(['name' => 'Unapproved seller listing', 'category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.category', $this->chicks->slug), $this))
        ->not->toContain('Unapproved seller listing');
});

it('returns 404 for a category that has been switched off', function () {
    $hidden = Category::factory()->create(['is_active' => false]);

    $this->get(route('catalogue.category', $hidden->slug))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Browsing the tree
// ---------------------------------------------------------------------------

it('includes everything filed beneath the category being browsed', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Chicks three levels down', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'A hand tool', 'category_id' => $this->tools->id]);

    // Browsing "Poultry" must reach the day-old chicks filed three levels down.
    expect(namesOn(route('catalogue.category', $this->poultry->slug), $this))
        ->toContain('Chicks three levels down')
        ->not->toContain('A hand tool');
});

it('gives a category page breadcrumbs back up the tree', function () {
    $crumbs = collect($this->get(route('catalogue.category', $this->chicks->slug))
        ->assertOk()
        ->viewData('page')['props']['breadcrumbs'])
        ->pluck('label');

    expect($crumbs->all())->toBe(['Poultry', 'Live birds', 'Day-old chicks']);
});

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

it('filters by price range, in Naira', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->pricedAt(500_000)->create(['name' => 'Cheap', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->pricedAt(5_000_000)->create(['name' => 'Mid', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->pricedAt(90_000_000)->create(['name' => 'Dear', 'category_id' => $this->chicks->id]);

    // ₦10,000 to ₦100,000 — the shopper types Naira, the column is kobo.
    $names = namesOn(route('catalogue.category', [$this->chicks->slug, 'min_price' => 10_000, 'max_price' => 100_000]), $this);

    expect($names)->toContain('Mid')->not->toContain('Cheap', 'Dear');
});

it('filters by the seller\'s state', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'From Oyo', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->kanoSeller, 'seller')->create(['name' => 'From Kano', 'category_id' => $this->chicks->id]);

    $names = namesOn(route('catalogue.category', [$this->chicks->slug, 'state' => ['Oyo']]), $this);

    expect($names)->toContain('From Oyo')->not->toContain('From Kano');
});

it('filters by condition', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'A new one', 'condition' => ProductCondition::New, 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'A used one', 'condition' => ProductCondition::Used, 'category_id' => $this->chicks->id]);

    $names = namesOn(route('catalogue.category', [$this->chicks->slug, 'condition' => ['used']]), $this);

    expect($names)->toContain('A used one')->not->toContain('A new one');
});

it('filters to negotiable listings only', function () {
    Product::factory()->negotiable()->for($this->oyoSeller, 'seller')->create(['name' => 'Open to offers', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Fixed price', 'is_negotiable' => false, 'category_id' => $this->chicks->id]);

    $names = namesOn(route('catalogue.category', [$this->chicks->slug, 'negotiable' => 1]), $this);

    expect($names)->toContain('Open to offers')->not->toContain('Fixed price');
});

it('filters to what is actually in stock', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Available now', 'stock_quantity' => 5, 'category_id' => $this->chicks->id]);
    Product::factory()->outOfStock()->for($this->oyoSeller, 'seller')->create(['name' => 'None left', 'category_id' => $this->chicks->id]);

    $names = namesOn(route('catalogue.category', [$this->chicks->slug, 'in_stock' => 1]), $this);

    expect($names)->toContain('Available now')->not->toContain('None left');
});

it('combines filters rather than letting the last one win', function () {
    Product::factory()->negotiable()->for($this->oyoSeller, 'seller')->pricedAt(2_000_000)->create(['name' => 'Matches both', 'category_id' => $this->chicks->id]);
    Product::factory()->negotiable()->for($this->kanoSeller, 'seller')->pricedAt(2_000_000)->create(['name' => 'Wrong state', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->pricedAt(2_000_000)->create(['name' => 'Not negotiable', 'is_negotiable' => false, 'category_id' => $this->chicks->id]);

    $names = namesOn(route('catalogue.category', [
        $this->chicks->slug,
        'state' => ['Oyo'],
        'negotiable' => 1,
    ]), $this);

    expect($names)->toBe(['Matches both']);
});

it('filters to live animals and to perishables', function () {
    Product::factory()->liveAnimal()->for($this->oyoSeller, 'seller')->create(['name' => 'Live birds', 'category_id' => $this->chicks->id]);
    Product::factory()->perishable()->for($this->oyoSeller, 'seller')->create(['name' => 'Fresh eggs', 'is_live_animal' => false, 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'A drinker', 'is_live_animal' => false, 'is_perishable' => false, 'category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.category', [$this->chicks->slug, 'live_animals' => 1]), $this))
        ->toContain('Live birds')->not->toContain('A drinker');

    expect(namesOn(route('catalogue.category', [$this->chicks->slug, 'perishable' => 1]), $this))
        ->toContain('Fresh eggs')->not->toContain('A drinker');
});

// ---------------------------------------------------------------------------
// Sorting
// ---------------------------------------------------------------------------

it('sorts by price in both directions', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->pricedAt(300_000)->create(['name' => 'Low', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->pricedAt(900_000)->create(['name' => 'High', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->pricedAt(600_000)->create(['name' => 'Middle', 'category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.category', [$this->chicks->slug, 'sort' => 'price_asc']), $this))
        ->toBe(['Low', 'Middle', 'High']);

    expect(namesOn(route('catalogue.category', [$this->chicks->slug, 'sort' => 'price_desc']), $this))
        ->toBe(['High', 'Middle', 'Low']);
});

it('falls back to newest when the sort is missing or nonsense', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Older', 'category_id' => $this->chicks->id, 'published_at' => now()->subWeek()]);
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Newer', 'category_id' => $this->chicks->id, 'published_at' => now()]);

    expect(namesOn(route('catalogue.category', [$this->chicks->slug, 'sort' => 'nonsense']), $this))
        ->toBe(['Newer', 'Older']);
});

// ---------------------------------------------------------------------------
// Search
// ---------------------------------------------------------------------------

it('finds a listing by a word in its name', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Broiler starter mash', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Plasson drinker', 'category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.search', ['q' => 'mash']), $this))
        ->toContain('Broiler starter mash')->not->toContain('Plasson drinker');
});

it('finds a listing by a word only in its description', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create([
        'name' => 'Assorted equipment',
        'description' => 'Includes a galvanised incubator tray for the hatchery.',
        'category_id' => $this->chicks->id,
    ]);

    expect(namesOn(route('catalogue.search', ['q' => 'incubator']), $this))->toContain('Assorted equipment');
});

it('requires every word the shopper typed', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Broiler starter mash', 'description' => 'Poultry feed.', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Layer starter crumbs', 'description' => 'Poultry feed.', 'category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.search', ['q' => 'broiler starter']), $this))
        ->toContain('Broiler starter mash')
        ->not->toContain('Layer starter crumbs');
});

it('does not treat a hyphen in what somebody typed as an exclusion', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Day-old broiler chicks', 'category_id' => $this->chicks->id]);

    // In boolean mode a bare "-old" would mean "must not contain old", quietly
    // returning the opposite of what was asked for.
    expect(namesOn(route('catalogue.search', ['q' => 'day-old']), $this))->toContain('Day-old broiler chicks');
});

it('returns nothing rather than everything for a search that matches nothing', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.search', ['q' => 'zzzznotathinganybodysells']), $this))->toBe([]);
});

it('shows everything when no search term is given', function () {
    Product::factory()->count(3)->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.search'), $this))->toHaveCount(3);
});

it('keeps the filters applied while searching', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Oyo mash', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->kanoSeller, 'seller')->create(['name' => 'Kano mash', 'category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.search', ['q' => 'mash', 'state' => ['Oyo']]), $this))
        ->toContain('Oyo mash')->not->toContain('Kano mash');
});

// ---------------------------------------------------------------------------
// Home, product and storefront
// ---------------------------------------------------------------------------

it('shows featured categories and the newest listings on the catalogue home', function () {
    Product::factory()->count(3)->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);

    $props = $this->get(route('catalogue.home'))->assertOk()->viewData('page')['props'];

    expect($props['featuredCategories'])->not->toBeEmpty()
        ->and($props['newestProducts'])->toHaveCount(3);
});

it('shows a product page with its seller, tiers and gallery', function () {
    $product = Product::factory()->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);
    $product->priceTiers()->create(['min_quantity' => 10, 'unit_price_kobo' => (int) ($product->price_kobo * 0.9)]);

    $props = $this->get(route('catalogue.product', $product->slug))->assertOk()->viewData('page')['props'];

    expect($props['product']['name'])->toBe($product->name)
        ->and($props['product']['seller']['business_name'])->toBe($this->oyoSeller->business_name)
        ->and($props['product']['price_tiers'])->toHaveCount(1)
        ->and($props['product']['price_tiers'][0]['saving_percent'])->toBe(10);
});

it('will not show a product that is not publicly visible', function () {
    $draft = Product::factory()->draft()->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);

    $this->get(route('catalogue.product', $draft->slug))->assertNotFound();
});

it('counts a view once per visitor per hour', function () {
    $product = Product::factory()->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id, 'views_count' => 0]);

    $this->get(route('catalogue.product', $product->slug));
    $this->get(route('catalogue.product', $product->slug));

    // A refresh must not inflate the number the seller is looking at.
    expect($product->fresh()->views_count)->toBe(1);
});

it('shows a seller storefront with only their own visible listings', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['name' => 'Theirs', 'category_id' => $this->chicks->id]);
    Product::factory()->for($this->kanoSeller, 'seller')->create(['name' => 'Somebody else\'s', 'category_id' => $this->chicks->id]);
    Product::factory()->draft()->for($this->oyoSeller, 'seller')->create(['name' => 'Their draft', 'category_id' => $this->chicks->id]);

    expect(namesOn(route('catalogue.storefront', $this->oyoSeller->slug), $this))
        ->toBe(['Theirs']);
});

it('will not show the storefront of a seller who is not approved', function () {
    $pending = SellerProfile::factory()->create();

    $this->get(route('catalogue.storefront', $pending->slug))->assertNotFound();
});

// ---------------------------------------------------------------------------
// The counts and the sort control reflect reality
// ---------------------------------------------------------------------------

it('counts listings across a category\'s whole subtree, not just its own leaf', function () {
    // Products are filed on leaves, so a direct count would show zero beside
    // "Live birds" while the day-old chicks beneath it are on sale.
    Product::factory()->count(2)->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);

    $props = $this->get(route('catalogue.category', $this->poultry->slug))->assertOk()->viewData('page')['props'];

    $liveBirds = collect($props['children'])->firstWhere('name', 'Live birds');

    expect($liveBirds['count'])->toBe(2);
});

it('counts a whole branch on the catalogue home', function () {
    Product::factory()->count(3)->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);

    $props = $this->get(route('catalogue.home'))->assertOk()->viewData('page')['props'];

    expect(collect($props['featuredCategories'])->firstWhere('name', 'Poultry')['count'])->toBe(3);
});

it('reports the sort actually in force so the control is not blank', function () {
    Product::factory()->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);

    $browse = $this->get(route('catalogue.category', $this->chicks->slug))->viewData('page')['props'];
    expect($browse['filters']['sort'])->toBe('newest');

    $searched = $this->get(route('catalogue.search', ['q' => 'anything']))->viewData('page')['props'];
    expect($searched['filters']['sort'])->toBe('relevance');

    $chosen = $this->get(route('catalogue.category', [$this->chicks->slug, 'sort' => 'price_asc']))->viewData('page')['props'];
    expect($chosen['filters']['sort'])->toBe('price_asc');

    $nonsense = $this->get(route('catalogue.category', [$this->chicks->slug, 'sort' => 'sideways']))->viewData('page')['props'];
    expect($nonsense['filters']['sort'])->toBe('newest');
});

it('keeps an option\'s price difference when a bulk tier applies', function () {
    $product = Product::factory()->for($this->oyoSeller, 'seller')->pricedAt(2_000_000)->create([
        'category_id' => $this->chicks->id,
    ]);

    $product->priceTiers()->create(['min_quantity' => 10, 'unit_price_kobo' => 1_800_000]);
    $small = $product->variants()->create(['name' => '25kg bag', 'price_delta_kobo' => 0, 'stock_quantity' => 5]);
    $large = $product->variants()->create(['name' => '50kg bag', 'price_delta_kobo' => 1_500_000, 'stock_quantity' => 5]);

    $product->load(['priceTiers', 'variants']);

    // Below the tier: the list price plus the option's difference.
    expect($product->unitPriceKoboFor(1, $small))->toBe(2_000_000)
        ->and($product->unitPriceKoboFor(1, $large))->toBe(3_500_000);

    // At the tier: the tier replaces the base, the difference still applies.
    // Letting the tier win outright would sell the 50kg bag at the 25kg price.
    expect($product->unitPriceKoboFor(10, $small))->toBe(1_800_000)
        ->and($product->unitPriceKoboFor(10, $large))->toBe(3_300_000)
        ->and($product->unitPriceKoboFor(10))->toBe(1_800_000);
});

it('sends each option\'s price difference to the product page', function () {
    $product = Product::factory()->for($this->oyoSeller, 'seller')->create(['category_id' => $this->chicks->id]);
    $product->variants()->create(['name' => '50kg bag', 'price_delta_kobo' => 1_500_000, 'stock_quantity' => 5]);

    $props = $this->get(route('catalogue.product', $product->slug))->assertOk()->viewData('page')['props'];

    expect($props['product']['variants'][0]['price_delta_kobo'])->toBe(1_500_000);
});
