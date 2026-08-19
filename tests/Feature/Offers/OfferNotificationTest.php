<?php

use App\Enums\RoleName;
use App\Mail\OfferAcceptedMail;
use App\Mail\OfferReceivedMail;
use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\BuyerRequestReviewed;
use App\Notifications\OfferAccepted;
use App\Notifications\OfferCountered;
use App\Notifications\OfferReceived;
use App\Notifications\OfferRejected;
use App\Services\Offers\BuyerRequestService;
use App\Services\Offers\OfferService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about what.
 *
 * Every one of these goes out on two channels and is queued, because a slow
 * mail provider must never hold up the request that caused it.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->offers = app(OfferService::class);
    $this->requests = app(BuyerRequestService::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);

    $this->buyer = User::factory()->create();
    $this->category = Category::factory()->create();
    $this->seller = SellerProfile::factory()->approved()->create();
    $this->sellerUser = $this->seller->user;

    $this->product = Product::factory()->for($this->seller, 'seller')->pricedAt(1_000_000)->create([
        'category_id' => $this->category->id,
        'stock_quantity' => 50,
        'min_order_quantity' => 1,
        'is_negotiable' => true,
    ]);
});

it('goes to the database and to email, queued', function () {
    $notification = new OfferReceived(
        Offer::factory()->create(),
    );

    expect($notification->via($this->sellerUser))->toBe(['database', 'mail'])
        // Queued, so a slow provider cannot hold up a web request.
        ->and($notification)->toBeInstanceOf(ShouldQueue::class);
});

it('tells the seller when an offer arrives', function () {
    Notification::fake();

    $this->offers->offerOnProduct($this->product, $this->buyer, 5, 850_000);

    Notification::assertSentTo($this->sellerUser, OfferReceived::class);
    Notification::assertNotSentTo($this->buyer, OfferReceived::class);
});

it('tells whoever is now waiting when somebody counters', function () {
    Notification::fake();

    $offer = $this->offers->offerOnProduct($this->product, $this->buyer, 5, 850_000);
    $this->offers->counter($offer, $this->sellerUser, 5, 920_000);

    // The ball is back with the buyer, so the buyer is the one told.
    Notification::assertSentTo($this->buyer, OfferCountered::class);
    Notification::assertNotSentTo($this->sellerUser, OfferCountered::class);
});

it('tells both sides when an offer is accepted, and tells them different things', function () {
    Notification::fake();

    $offer = $this->offers->offerOnProduct($this->product, $this->buyer, 5, 850_000);
    $this->offers->accept($offer, $this->sellerUser);

    // The buyer gets a link to pay; the seller gets told to expect the money.
    Notification::assertSentTo(
        $this->buyer,
        OfferAccepted::class,
        fn (OfferAccepted $n): bool => $n->forBuyer && $n->purchase !== null,
    );

    Notification::assertSentTo(
        $this->sellerUser,
        OfferAccepted::class,
        fn (OfferAccepted $n): bool => $n->forBuyer === false,
    );
});

it('tells the loser when a rival offer wins', function () {
    Notification::fake();

    $request = BuyerRequest::factory()->open()->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->category->id,
        'quantity' => 100,
        'accepts_partial_fulfilment' => true,
    ]);

    $this->seller->categories()->sync([$this->category->id]);

    $loserProfile = SellerProfile::factory()->approved()->create();
    $loserProfile->categories()->sync([$this->category->id]);

    $winning = $this->offers->offerOnRequest($request, $this->seller, 100, 880_000);
    $this->offers->offerOnRequest($request->fresh(), $loserProfile, 100, 950_000);

    $this->offers->accept($winning->fresh(), $this->buyer);

    // Silence is how a seller learns not to bother answering ads.
    Notification::assertSentTo($loserProfile->user, OfferRejected::class);
});

it('tells the buyer when their request is published, and when it is not', function () {
    Notification::fake();

    $approved = BuyerRequest::factory()->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->category->id,
    ]);

    $this->requests->approve($approved, $this->admin);

    Notification::assertSentTo(
        $this->buyer,
        BuyerRequestReviewed::class,
        fn (BuyerRequestReviewed $n): bool => $n->approved === true,
    );

    $refused = BuyerRequest::factory()->create([
        'user_id' => $this->buyer->id,
        'category_id' => $this->category->id,
    ]);

    $this->requests->reject($refused, $this->admin, 'Please leave your phone number out of it.');

    Notification::assertSentTo(
        $this->buyer,
        BuyerRequestReviewed::class,
        fn (BuyerRequestReviewed $n): bool => $n->approved === false,
    );
});

it('writes something readable into the database channel', function () {
    $offer = $this->offers->offerOnProduct($this->product, $this->buyer, 5, 850_000);

    $row = $this->sellerUser->notifications()->latest()->first();

    expect($row)->not->toBeNull()
        ->and($row->data['kind'])->toBe('offer.received')
        ->and($row->data['title'])->toContain($this->buyer->displayName())
        // ₦8,500 × 5.
        ->and($row->data['body'])->toContain('₦42,500')
        ->and($row->data['url'])->toContain('/seller/offers')
        ->and($row->data['offer_id'])->toBe($offer->id);
});

it('shows them in the app and marks them read on the way through', function () {
    $this->offers->offerOnProduct($this->product, $this->buyer, 5, 850_000);

    $this->actingAs($this->sellerUser)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Notifications/Index')
            ->count('notifications', 1)
            ->where('unread', 1)
            ->where('notifications.0.read', false));

    $row = $this->sellerUser->notifications()->sole();

    $this->actingAs($this->sellerUser)
        ->get(route('notifications.read', $row->id))
        ->assertRedirect();

    expect($row->fresh()->read_at)->not->toBeNull();
});

it('lets nobody read somebody else\'s notifications', function () {
    $this->offers->offerOnProduct($this->product, $this->buyer, 5, 850_000);

    $row = $this->sellerUser->notifications()->sole();

    $this->actingAs($this->buyer)
        ->get(route('notifications.read', $row->id))
        ->assertNotFound();
});

it('renders the offer emails without falling over', function () {
    // A branded template that throws is a notification nobody ever receives,
    // and a queued job that fails silently is how you find out weeks later.
    $offer = $this->offers->offerOnProduct($this->product, $this->buyer, 5, 850_000);

    $rendered = (new OfferReceivedMail($offer, '/seller/offers'))->render();

    expect($rendered)->toContain('₦8,500')->toContain('₦42,500');

    $this->offers->accept($offer->fresh(), $this->sellerUser);

    $accepted = (new OfferAcceptedMail(
        $offer->fresh(),
        $offer->fresh()->purchase,
        forBuyer: true,
    ))->render();

    expect($accepted)->toContain('/agreed/');
});
