<?php

use App\Enums\BuyerRequestStatus;
use App\Enums\RoleName;
use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Offers\OfferService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The board as people actually meet it: over HTTP.
 *
 * The rule these mostly exist to hold is that a seller can see how many rivals
 * they have but never what any of them bid.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->offers = app(OfferService::class);

    $this->buyer = User::factory()->create();
    $this->poultry = Category::factory()->create(['name' => 'Poultry']);
    $this->tools = Category::factory()->create(['name' => 'Tools']);

    $this->request = BuyerRequest::factory()->open()->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->poultry->id,
        'delivery_state' => 'Oyo',
        'quantity' => 100,
        'accepts_partial_fulfilment' => true,
    ]);

    $this->makeSeller = function (Category $category): array {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Seller->value);
        $seller = SellerProfile::factory()->approved()->create(['user_id' => $user->id]);
        $seller->categories()->sync([$category->id]);

        return [$user, $seller->fresh()];
    };
});

// ---------------------------------------------------------------------------
// The board
// ---------------------------------------------------------------------------

it('shows the board to anybody, signed in or not', function () {
    $this->get(route('requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Requests/Index')->count('requests', 1));
});

it('keeps unapproved requests off the board', function () {
    BuyerRequest::factory()->create(['user_id' => $this->buyer->id, 'category_id' => $this->poultry->id]);

    $this->get(route('requests.index'))
        ->assertInertia(fn ($page) => $page->count('requests', 1));
});

it('filters by category branch and by state', function () {
    BuyerRequest::factory()->open()->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->tools->id,
        'delivery_state' => 'Lagos',
    ]);

    $this->get(route('requests.index', ['category' => $this->poultry->slug]))
        ->assertInertia(fn ($page) => $page->count('requests', 1));

    $this->get(route('requests.index', ['state' => 'Lagos']))
        ->assertInertia(fn ($page) => $page->count('requests', 1));

    $this->get(route('requests.index', ['state' => 'Kano']))
        ->assertInertia(fn ($page) => $page->count('requests', 0));
});

it('shows how many offers there are and never what they were', function () {
    [, $one] = ($this->makeSeller)($this->poultry);
    [, $two] = ($this->makeSeller)($this->poultry);

    $this->offers->offerOnRequest($this->request, $one, 100, 880_000);
    $this->offers->offerOnRequest($this->request->fresh(), $two, 100, 1_200_000);

    $response = $this->get(route('requests.show', $this->request->slug))->assertOk();

    $props = json_encode($response->viewData('page')['props']);

    // A board that shows the best price so far is a board where everybody
    // shaves a naira off it and nobody bids their real number.
    expect($props)
        ->toContain('"offer_count":2')
        ->not->toContain('880000')
        ->not->toContain('₦8,800');
});

it('shows a rival seller the count but not the prices', function () {
    [, $one] = ($this->makeSeller)($this->poultry);
    [$rivalUser] = ($this->makeSeller)($this->poultry);

    $this->offers->offerOnRequest($this->request, $one, 100, 880_000);

    $response = $this->actingAs($rivalUser)
        ->get(route('requests.show', $this->request->slug))
        ->assertOk();

    $props = json_encode($response->viewData('page')['props']);

    expect($props)->toContain('"offer_count":1')
        ->not->toContain('8800');
});

it('shows a seller their own offer back', function () {
    [$sellerUser, $seller] = ($this->makeSeller)($this->poultry);

    $this->offers->offerOnRequest($this->request, $seller, 40, 750_000);

    $this->actingAs($sellerUser)
        ->get(route('requests.show', $this->request->slug))
        ->assertInertia(fn ($page) => $page
            ->where('seller.existing_offer.quantity', 40)
            ->where('seller.existing_offer.unit_price', '₦7,500'));
});

// ---------------------------------------------------------------------------
// Offering over HTTP
// ---------------------------------------------------------------------------

it('lets an eligible seller offer through the form', function () {
    [$sellerUser] = ($this->makeSeller)($this->poultry);

    $this->actingAs($sellerUser)
        ->post(route('requests.offer', $this->request->slug), [
            'quantity' => 100,
            'unit_price' => 8800,
            'delivery_days' => 4,
            'message' => 'Ready on Thursday.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Offer::query()->sole()->unit_price_kobo)->toBe(880_000);
});

it('turns away a seller from the wrong category with a sentence, not a crash', function () {
    [$sellerUser] = ($this->makeSeller)($this->tools);

    $this->actingAs($sellerUser)
        ->post(route('requests.offer', $this->request->slug), ['quantity' => 10, 'unit_price' => 8800])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Offer::query()->count())->toBe(0);
});

it('turns away somebody who is not a seller at all', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('requests.offer', $this->request->slug), ['quantity' => 10, 'unit_price' => 8800])
        ->assertRedirect()
        ->assertSessionHas('error');
});

// ---------------------------------------------------------------------------
// The buyer's own screens
// ---------------------------------------------------------------------------

it('lays every offer out for the buyer, cheapest first', function () {
    [, $dear] = ($this->makeSeller)($this->poultry);
    [, $cheap] = ($this->makeSeller)($this->poultry);

    $this->offers->offerOnRequest($this->request, $dear, 100, 1_200_000);
    $this->offers->offerOnRequest($this->request->fresh(), $cheap, 100, 880_000);

    $this->actingAs($this->buyer)
        ->get(route('requests.manage', $this->request->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Requests/Manage')
            ->count('offers', 2)
            ->where('offers.0.unit_price_kobo', 880_000)
            ->where('offers.1.unit_price_kobo', 1_200_000));
});

it('lets nobody else read the buyer\'s offers', function () {
    [$sellerUser] = ($this->makeSeller)($this->poultry);

    $this->actingAs($sellerUser)
        ->get(route('requests.manage', $this->request->slug))
        ->assertForbidden();
});

it('lets the buyer accept one through the form', function () {
    [, $seller] = ($this->makeSeller)($this->poultry);
    $offer = $this->offers->offerOnRequest($this->request, $seller, 100, 880_000);

    $this->actingAs($this->buyer)
        ->post(route('offers.respond', $offer->id), ['decision' => 'accept'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->request->fresh()->status)->toBe(BuyerRequestStatus::OfferAccepted)
        ->and($offer->fresh()->purchase)->not->toBeNull();
});

it('sends the buyer\'s own request straight to the manage screen', function () {
    $mine = BuyerRequest::factory()->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->poultry->id,
    ]);

    // Not yet public, so a stranger gets nothing.
    $this->actingAs(User::factory()->create())
        ->get(route('requests.show', $mine->slug))
        ->assertNotFound();

    $this->actingAs($this->buyer)
        ->get(route('requests.show', $mine->slug))
        ->assertRedirect(route('requests.manage', $mine->slug));
});

it('posts a request for checking rather than straight to the board', function () {
    $this->actingAs($this->buyer)
        ->post(route('requests.store'), [
            'title' => 'Wanted: 300 point-of-lay pullets',
            'description' => 'Isa Brown or similar, vaccinated, ready this month. Collection possible.',
            'category_id' => $this->poultry->id,
            'quantity' => 300,
            'unit' => 'bird',
            'budget_min' => 2500,
            'budget_max' => 3200,
            'delivery_state' => 'Oyo',
            'delivery_lga' => 'Akinyele',
            'accepts_partial_fulfilment' => true,
        ])
        ->assertRedirect(route('requests.mine'));

    $created = BuyerRequest::query()->latest('id')->first();

    expect($created->status)->toBe(BuyerRequestStatus::PendingApproval)
        ->and($created->budget_min_kobo)->toBe(250_000)
        ->and($created->expires_at)->toBeNull();

    // And it is not on the board until somebody has read it.
    $this->get(route('requests.index'))
        ->assertInertia(fn ($page) => $page->count('requests', 1));
});

it('lets the buyer close their own request from the page', function () {
    $this->actingAs($this->buyer)
        ->post(route('requests.close', $this->request->slug))
        ->assertRedirect();

    expect($this->request->fresh()->status)->toBe(BuyerRequestStatus::Closed);
});

// ---------------------------------------------------------------------------
// Haggling on a listing, over HTTP
// ---------------------------------------------------------------------------

it('shows a buyer their own live offer on a listing and nobody else\'s', function () {
    [, $seller] = ($this->makeSeller)($this->poultry);

    $product = Product::factory()->for($seller, 'seller')->pricedAt(1_000_000)->create([
        'category_id' => $this->poultry->id,
        'stock_quantity' => 100,
        'min_order_quantity' => 1,
        'is_negotiable' => true,
    ]);

    $mine = $this->offers->offerOnProduct($product, $this->buyer, 5, 850_000);

    $this->actingAs($this->buyer)
        ->get(route('catalogue.product', $product->slug))
        ->assertInertia(fn ($page) => $page
            ->where('product.my_offer.id', $mine->id)
            ->where('product.my_offer.awaiting_me', false));

    // A stranger sees no offer at all.
    $this->actingAs(User::factory()->create())
        ->get(route('catalogue.product', $product->slug))
        ->assertInertia(fn ($page) => $page->where('product.my_offer', null));
});
