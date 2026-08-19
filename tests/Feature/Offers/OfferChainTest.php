<?php

use App\Enums\OfferStatus;
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
 * The haggle, and the record it leaves.
 *
 * A counter-offer is a new row pointing at the one it answers; nothing is ever
 * rewritten. These tests exist because the value of that design is entirely in
 * being able to read the argument back afterwards, and a chain that quietly
 * loses a link is worse than no chain at all.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->offers = app(OfferService::class);

    $this->category = Category::factory()->create();
    $this->sellerProfile = SellerProfile::factory()->approved()->create();
    $this->sellerUser = $this->sellerProfile->user;
    $this->buyer = User::factory()->create();

    // ₦18,500 a bag, and the seller is open to offers.
    $this->product = Product::factory()
        ->for($this->sellerProfile, 'seller')
        ->pricedAt(1_850_000)
        ->create([
            'category_id' => $this->category->id,
            'stock_quantity' => 100,
            'min_order_quantity' => 1,
            'is_negotiable' => true,
        ]);

    $this->open = fn (int $qty = 10, int $price = 1_600_000): Offer => $this->offers->offerOnProduct(
        $this->product->fresh(),
        $this->buyer,
        $qty,
        $price,
        'Can you do better for ten bags?',
    );
});

it('records the whole haggle as a chain nobody rewrote', function () {
    $first = ($this->open)();

    $second = $this->offers->counter($first, $this->sellerUser, 10, 1_750_000, 'Best I can do.');
    $third = $this->offers->counter($second->fresh(), $this->buyer, 10, 1_700_000, 'Meet me here.');

    // Each answered offer is superseded, not erased.
    expect($first->fresh()->status)->toBe(OfferStatus::Countered)
        ->and($second->fresh()->status)->toBe(OfferStatus::Countered)
        ->and($third->fresh()->status)->toBe(OfferStatus::Pending);

    $chain = $third->fresh()->chain();

    expect($chain->pluck('id')->all())->toBe([$first->id, $second->id, $third->id])
        ->and($chain->pluck('unit_price_kobo')->all())->toBe([1_600_000, 1_750_000, 1_700_000])
        // Every link knows where it sits.
        ->and($third->fresh()->round())->toBe(3)
        ->and($third->fresh()->root()->id)->toBe($first->id);
});

it('swaps who has to answer with every counter', function () {
    $first = ($this->open)();

    expect($first->initiator_id)->toBe($this->buyer->id)
        ->and($first->responder_id)->toBe($this->sellerUser->id);

    $second = $this->offers->counter($first, $this->sellerUser, 10, 1_750_000);

    // The seller has spoken; the ball is back with the buyer.
    expect($second->initiator_id)->toBe($this->sellerUser->id)
        ->and($second->responder_id)->toBe($this->buyer->id);

    $third = $this->offers->counter($second->fresh(), $this->buyer, 10, 1_700_000);

    expect($third->initiator_id)->toBe($this->buyer->id)
        ->and($third->responder_id)->toBe($this->sellerUser->id);
});

it('keeps the total in step with the price and the quantity', function () {
    $offer = ($this->open)(qty: 12, price: 1_500_000);

    expect($offer->total_price_kobo)->toBe(18_000_000);

    // Even when somebody edits the parts directly.
    $offer->forceFill(['quantity' => 20])->save();

    expect($offer->fresh()->total_price_kobo)->toBe(30_000_000);
});

it('lets nobody but the responder answer', function () {
    $offer = ($this->open)();
    $stranger = User::factory()->create();

    expect(fn () => $this->offers->accept($offer, $stranger))->toThrow(RuntimeException::class);
    expect(fn () => $this->offers->accept($offer, $this->buyer))->toThrow(RuntimeException::class);

    // The seller, whose listing it is, can.
    $this->offers->accept($offer->fresh(), $this->sellerUser);

    expect($offer->fresh()->status)->toBe(OfferStatus::Accepted);
});

it('will not answer the same offer twice', function () {
    $offer = ($this->open)();

    $this->offers->reject($offer, $this->sellerUser);

    expect(fn () => $this->offers->accept($offer->fresh(), $this->sellerUser))
        ->toThrow(RuntimeException::class);
});

it('will not accept an offer that ran out of time', function () {
    $offer = ($this->open)();

    $offer->forceFill(['expires_at' => now()->subMinute()])->save();

    expect($offer->fresh()->isOpen())->toBeFalse();

    expect(fn () => $this->offers->accept($offer->fresh(), $this->sellerUser))
        ->toThrow(RuntimeException::class);

    // And the status still says pending until the sweep catches up, which is
    // exactly why acceptance checks the clock rather than the column.
    expect($offer->fresh()->status)->toBe(OfferStatus::Pending);
});

it('marks lapsed offers expired when the sweep runs', function () {
    $live = ($this->open)();
    $lapsed = Offer::factory()->expired()->create();

    expect($this->offers->expireDue())->toBe(1)
        ->and($lapsed->fresh()->status)->toBe(OfferStatus::Expired)
        ->and($live->fresh()->status)->toBe(OfferStatus::Pending);
});

it('lets a buyer take back an offer nobody has answered', function () {
    $offer = ($this->open)();

    $this->offers->withdraw($offer, $this->buyer);

    expect($offer->fresh()->status)->toBe(OfferStatus::Withdrawn);

    // But not the seller, who is not the one who made it.
    $another = ($this->open)();

    expect(fn () => $this->offers->withdraw($another, $this->sellerUser))
        ->toThrow(RuntimeException::class);
});

it('refuses an offer on a listing that does not take them', function () {
    $fixed = Product::factory()->for($this->sellerProfile, 'seller')->create([
        'category_id' => $this->category->id,
        'is_negotiable' => false,
        'stock_quantity' => 10,
    ]);

    expect(fn () => $this->offers->offerOnProduct($fixed, $this->buyer, 1, 100_000))
        ->toThrow(RuntimeException::class);
});

it('refuses a second offer while one is still waiting', function () {
    ($this->open)();

    expect(fn () => ($this->open)())->toThrow(RuntimeException::class);

    expect(Offer::query()->count())->toBe(1);
});

it('refuses an offer on your own listing', function () {
    expect(fn () => $this->offers->offerOnProduct($this->product, $this->sellerUser, 1, 100_000))
        ->toThrow(RuntimeException::class);
});

it('refuses more than the seller actually has', function () {
    expect(fn () => ($this->open)(qty: 101))->toThrow(RuntimeException::class);
});
