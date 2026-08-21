<?php

use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\SubOrderStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Notifications\CoursePurchased;
use App\Notifications\OrderPaid;
use App\Notifications\OrderShipped;
use App\Notifications\SellerOrderReceived;
use App\Services\Orders\FulfilmentService;
use App\Services\Orders\OrderNotifier;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Somebody is told when money moves.
 *
 * Until this was written, nothing on the platform notified anybody that an
 * order had been paid for or shipped. A buyer parted with money and heard
 * nothing; a seller had an order waiting and did not know.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->buyer = User::factory()->create();

    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);
    $this->sellerUser = $sellerUser;
    $this->seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $this->order = Order::factory()->create([
        'user_id' => $this->buyer->id,
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ]);

    $this->subOrder = SubOrder::factory()->create([
        'order_id' => $this->order->id,
        'seller_id' => $this->seller->id,
        'status' => SubOrderStatus::Accepted,
    ]);
});

it('tells the buyer their payment landed', function (): void {
    Notification::fake();

    app(OrderNotifier::class)->paid($this->order);

    Notification::assertSentTo($this->buyer, OrderPaid::class);
});

it('tells each seller they have something to pack', function (): void {
    Notification::fake();

    app(OrderNotifier::class)->paid($this->order);

    // The reason the buyer eventually gets what they paid for: an order nobody
    // knows about does not get packed.
    Notification::assertSentTo($this->sellerUser, SellerOrderReceived::class);
});

it('tells the buyer when it ships', function (): void {
    Notification::fake();

    app(FulfilmentService::class)->markShipped($this->subOrder);

    Notification::assertSentTo($this->buyer, OrderShipped::class);
});

it('does not tell the seller their own order shipped', function (): void {
    Notification::fake();

    app(FulfilmentService::class)->markShipped($this->subOrder);

    Notification::assertNotSentTo($this->sellerUser, OrderShipped::class);
});

it('lets a payment succeed even when the notification fails', function (): void {
    /*
     * The property that matters most here.
     *
     * The caller has already taken somebody's money and written the ledger. An
     * exception at this point would be reported as a failed webhook, the
     * gateway would redeliver, and the redelivery would find the order already
     * paid and do nothing — leaving a real payment looking like a failure for
     * good.
     */
    Notification::shouldReceive('send')->andThrow(new RuntimeException('the mail provider is down'));

    expect(fn () => app(OrderNotifier::class)->paid($this->order))->not->toThrow(Throwable::class);
});

it('tells a course buyer their course is open', function (): void {
    Notification::fake();

    $course = Course::factory()->published()->create();

    $enrolment = Enrolment::query()->create([
        'user_id' => $this->buyer->id,
        'course_id' => $course->id,
        'order_id' => $this->order->id,
        'price_paid_kobo' => 1_500_000,
    ]);

    app(OrderNotifier::class)->coursesOpened($this->order);

    /*
     * A course order produces no sub-orders at all, so the paid() loop tells
     * nobody about it. Without this branch somebody buys training and hears
     * nothing — and course sales are final, so they have no way out.
     */
    Notification::assertSentTo(
        $this->buyer,
        CoursePurchased::class,
        fn ($notification): bool => $notification->enrolment->is($enrolment),
    );
});
