<?php

use App\Enums\BuyerRequestStatus;
use App\Enums\OfferStatus;
use App\Enums\RoleName;
use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\Offer;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\BuyerRequestExpiring;
use App\Notifications\BuyerRequestReviewed;
use App\Notifications\OfferReceived;
use App\Services\Offers\BuyerRequestService;
use App\Services\Offers\OfferService;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Wanted ads: who may answer them, and what closes them.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->requests = app(BuyerRequestService::class);
    $this->offers = app(OfferService::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);

    $this->buyer = User::factory()->create();

    // Poultry › Feed, so the ancestor rule has something to walk.
    $this->poultry = Category::factory()->create(['name' => 'Poultry', 'parent_id' => null]);
    $this->feed = Category::factory()->create(['name' => 'Poultry feed', 'parent_id' => $this->poultry->id]);
    $this->tools = Category::factory()->create(['name' => 'Tools', 'parent_id' => null]);

    $this->request = BuyerRequest::factory()->open()->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->feed->id,
        'quantity' => 200,
        'accepts_partial_fulfilment' => true,
    ]);

    $this->sellerIn = function (Category $category, bool $approved = true): SellerProfile {
        $seller = SellerProfile::factory()->when($approved, fn ($f) => $f->approved())->create();
        $seller->categories()->sync([$category->id]);

        return $seller->fresh();
    };
});

// ---------------------------------------------------------------------------
// Who may answer
// ---------------------------------------------------------------------------

it('lets an approved seller in the right category answer', function () {
    $seller = ($this->sellerIn)($this->feed);

    $offer = $this->offers->offerOnRequest($this->request, $seller, 200, 900_000, 'Fresh stock, milled Tuesday.', 3);

    expect($offer->status)->toBe(OfferStatus::Pending)
        ->and($offer->responder_id)->toBe($this->buyer->id)
        ->and($offer->seller_id)->toBe($seller->id)
        ->and($offer->delivery_days)->toBe(3);

    Notification::assertSentTo($this->buyer, OfferReceived::class);
});

it('lets a seller registered for the parent category answer a child', function () {
    // Registered for "Poultry"; the request is under "Poultry feed". Nobody
    // registers against every leaf of a tree this deep.
    $seller = ($this->sellerIn)($this->poultry);

    $offer = $this->offers->offerOnRequest($this->request, $seller, 100, 900_000);

    expect($offer->id)->not->toBeNull();
});

it('refuses a seller who trades in something else entirely', function () {
    $seller = ($this->sellerIn)($this->tools);

    expect(fn () => $this->offers->offerOnRequest($this->request, $seller, 100, 900_000))
        ->toThrow(RuntimeException::class);

    expect(Offer::query()->count())->toBe(0);
});

it('refuses a seller whose application has not been approved', function () {
    $seller = ($this->sellerIn)($this->feed, approved: false);

    expect(fn () => $this->offers->offerOnRequest($this->request, $seller, 100, 900_000))
        ->toThrow(RuntimeException::class);
});

it('refuses somebody who is not a seller at all', function () {
    expect(fn () => $this->offers->offerOnRequest($this->request, null, 100, 900_000))
        ->toThrow(RuntimeException::class);
});

it('refuses a part-load when the buyer said all or nothing', function () {
    $this->request->forceFill(['accepts_partial_fulfilment' => false])->save();
    $seller = ($this->sellerIn)($this->feed);

    expect(fn () => $this->offers->offerOnRequest($this->request->fresh(), $seller, 150, 900_000))
        ->toThrow(RuntimeException::class);

    // All of it is fine.
    $offer = $this->offers->offerOnRequest($this->request->fresh(), $seller, 200, 900_000);

    expect($offer->quantity)->toBe(200);
});

it('refuses more than was asked for', function () {
    $seller = ($this->sellerIn)($this->feed);

    expect(fn () => $this->offers->offerOnRequest($this->request, $seller, 201, 900_000))
        ->toThrow(RuntimeException::class);
});

it('refuses a second offer from the same seller while one is waiting', function () {
    $seller = ($this->sellerIn)($this->feed);

    $this->offers->offerOnRequest($this->request, $seller, 100, 900_000);

    expect(fn () => $this->offers->offerOnRequest($this->request->fresh(), $seller, 100, 850_000))
        ->toThrow(RuntimeException::class);
});

it('refuses offers on a request that is not open', function () {
    $this->request->forceFill(['status' => BuyerRequestStatus::Closed])->save();
    $seller = ($this->sellerIn)($this->feed);

    expect(fn () => $this->offers->offerOnRequest($this->request->fresh(), $seller, 100, 900_000))
        ->toThrow(RuntimeException::class);
});

it('never lets an offer outlive the request it stands on', function () {
    $this->settings->set('offer_expiry_hours', '240', 'int', 'platform');

    $this->request->forceFill(['expires_at' => now()->addHours(6)])->save();
    $seller = ($this->sellerIn)($this->feed);

    $offer = $this->offers->offerOnRequest($this->request->fresh(), $seller, 100, 900_000);

    // Ten days would outlast the request by nine.
    expect($offer->expires_at->lessThanOrEqualTo($this->request->fresh()->expires_at))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Accepting
// ---------------------------------------------------------------------------

it('closes the request and every rival offer when the buyer accepts one', function () {
    $winner = ($this->sellerIn)($this->feed);
    $loser = ($this->sellerIn)($this->feed);

    $good = $this->offers->offerOnRequest($this->request, $winner, 200, 880_000);
    $worse = $this->offers->offerOnRequest($this->request->fresh(), $loser, 200, 950_000);

    $this->offers->accept($good->fresh(), $this->buyer);

    expect($good->fresh()->status)->toBe(OfferStatus::Accepted)
        // Nobody is left waiting for an answer that will never come.
        ->and($worse->fresh()->status)->toBe(OfferStatus::Rejected)
        ->and($this->request->fresh()->status)->toBe(BuyerRequestStatus::OfferAccepted);
});

it('mints a private purchase at the agreed price when a request offer is accepted', function () {
    $seller = ($this->sellerIn)($this->feed);
    $offer = $this->offers->offerOnRequest($this->request, $seller, 200, 880_000);

    $this->offers->accept($offer, $this->buyer);

    $purchase = $offer->fresh()->purchase;

    expect($purchase)->not->toBeNull()
        ->and($purchase->buyer_id)->toBe($this->buyer->id)
        ->and($purchase->seller_id)->toBe($seller->id)
        ->and($purchase->quantity)->toBe(200)
        ->and($purchase->unit_price_kobo)->toBe(880_000)
        ->and($purchase->buyer_request_id)->toBe($this->request->id)
        // No listing, so nothing to reserve.
        ->and($purchase->reserved_quantity)->toBe(0);
});

// ---------------------------------------------------------------------------
// Approval and expiry
// ---------------------------------------------------------------------------

it('starts the clock when an administrator publishes it, not when it was written', function () {
    $this->settings->set('buyer_request_expiry_days', '10', 'int', 'platform');

    $pending = BuyerRequest::factory()->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->feed->id,
        'created_at' => now()->subDays(4),
    ]);

    $this->requests->approve($pending, $this->admin);

    $pending->refresh();

    expect($pending->status)->toBe(BuyerRequestStatus::Open)
        ->and($pending->approved_by)->toBe($this->admin->id)
        // Ten days from now, not six days from a submission that sat in a queue.
        ->and($pending->expires_at->diffInDays(now()->addDays(10), absolute: true))->toBeLessThan(1);

    Notification::assertSentTo($this->buyer, BuyerRequestReviewed::class);
});

it('demands a reason before an administrator can turn one down', function () {
    $pending = BuyerRequest::factory()->create(['user_id' => $this->buyer->id, 'category_id' => $this->feed->id]);

    expect(fn () => $this->requests->reject($pending, $this->admin, '  '))
        ->toThrow(RuntimeException::class);

    $this->requests->reject($pending, $this->admin, 'Please do not put your phone number in the description.');

    expect($pending->fresh()->status)->toBe(BuyerRequestStatus::Rejected)
        ->and($pending->fresh()->rejection_reason)->toContain('phone number');
});

it('will not review the same request twice', function () {
    $this->requests->approve($this->request->fresh()->forceFill(['status' => BuyerRequestStatus::PendingApproval]), $this->admin);

    expect(fn () => $this->requests->approve($this->request->fresh(), $this->admin))
        ->toThrow(RuntimeException::class);
});

it('expires a request that ran out, and answers everybody still waiting', function () {
    $seller = ($this->sellerIn)($this->feed);
    $offer = $this->offers->offerOnRequest($this->request, $seller, 100, 900_000);

    $this->request->forceFill(['expires_at' => now()->subHour()])->save();

    expect($this->requests->expireDue())->toBe(1);

    expect($this->request->fresh()->status)->toBe(BuyerRequestStatus::Expired)
        // A seller left hanging is a seller who stops answering ads.
        ->and($offer->fresh()->status)->toBe(OfferStatus::Rejected);
});

it('warns the buyer once, three days out', function () {
    $soon = BuyerRequest::factory()->expiringIn(2)->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->feed->id,
    ]);

    BuyerRequest::factory()->expiringIn(9)->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->feed->id,
    ]);

    expect($this->requests->warnExpiring())->toBe(1)
        ->and($soon->fresh()->expiry_warned_at)->not->toBeNull();

    Notification::assertSentToTimes($this->buyer, BuyerRequestExpiring::class, 1);

    // And never again for the same request, however often the sweep runs.
    expect($this->requests->warnExpiring())->toBe(0);

    Notification::assertSentToTimes($this->buyer, BuyerRequestExpiring::class, 1);
});

it('lets the buyer close early and tells the sellers waiting', function () {
    $seller = ($this->sellerIn)($this->feed);
    $offer = $this->offers->offerOnRequest($this->request, $seller, 100, 900_000);

    $this->requests->close($this->request->fresh(), $this->buyer);

    expect($this->request->fresh()->status)->toBe(BuyerRequestStatus::Closed)
        ->and($offer->fresh()->status)->toBe(OfferStatus::Rejected);
});

it('lets nobody else close somebody\'s request', function () {
    expect(fn () => $this->requests->close($this->request, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});
