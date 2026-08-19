<?php

use App\Enums\DeliveryMethod;
use App\Models\Category;
use App\Models\NegotiatedPurchase;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Offers\NegotiatedCheckout;
use App\Services\Offers\OfferService;
use App\Services\Payments\PaymentProcessor;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\WalletService;
use App\Support\Commission;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * What an accepted offer is actually worth.
 *
 * The whole point of haggling is that the buyer pays the price they argued
 * for. If the order comes out at the listed price, or the commission is taken
 * on the listed price, the feature is a lie told expensively.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->offers = app(OfferService::class);
    $this->checkout = app(NegotiatedCheckout::class);

    $this->settings->set('marketplace_commission_percent', '5', 'float', 'platform');

    $this->category = Category::factory()->create();
    $this->seller = SellerProfile::factory()->approved()->create(['state' => 'Oyo']);
    $this->seller->deliveryRates()->create(['state' => 'Lagos', 'fee_kobo' => 500_000, 'is_active' => true]);

    $this->buyer = User::factory()->create();

    // Listed at ₦20,000; the haggle lands on ₦17,000.
    $this->product = Product::factory()
        ->for($this->seller, 'seller')
        ->pricedAt(2_000_000)
        ->create([
            'category_id' => $this->category->id,
            'stock_quantity' => 50,
            'min_order_quantity' => 1,
            'is_negotiable' => true,
        ]);

    $this->address = [
        'name' => 'Aisha Bello',
        'phone' => '08030000000',
        'address' => '14 Taiwo Road',
        'state' => 'Lagos',
        'lga' => 'Kosofe',
    ];

    $this->agree = function (int $qty = 10, int $price = 1_700_000): NegotiatedPurchase {
        $offer = $this->offers->offerOnProduct($this->product->fresh(), $this->buyer, $qty, $price);
        $this->offers->accept($offer, $this->seller->user);

        return $offer->fresh()->purchase;
    };
});

it('produces an order at the price that was agreed, not the price on the shelf', function () {
    $purchase = ($this->agree)();

    $order = $this->checkout->build(
        $purchase,
        $this->buyer,
        $this->address,
        DeliveryMethod::SellerArranged,
    );

    $subOrder = $order->subOrders()->sole();
    $item = $subOrder->items()->sole();

    // ₦17,000 × 10, not ₦20,000 × 10.
    expect($item->unit_price_kobo)->toBe(1_700_000)
        ->and($item->quantity)->toBe(10)
        ->and($item->line_total_kobo)->toBe(17_000_000)
        ->and($order->subtotal_kobo)->toBe(17_000_000)
        ->and($order->delivery_total_kobo)->toBe(500_000)
        ->and($order->grand_total_kobo)->toBe(17_500_000);
});

it('takes commission on the negotiated price', function () {
    $purchase = ($this->agree)();

    $order = $this->checkout->build($purchase, $this->buyer, $this->address, DeliveryMethod::BuyerPickup);
    $subOrder = $order->subOrders()->sole();

    $expected = Commission::on(17_000_000, 5);

    expect($subOrder->subtotal_kobo)->toBe(17_000_000)
        ->and($subOrder->commission_amount_kobo)->toBe($expected->commissionKobo)
        ->and($subOrder->seller_payout_amount_kobo)->toBe($expected->payoutKobo)
        ->and((float) $subOrder->commission_percent_snapshot)->toBe(5.0)
        // And it still adds back exactly.
        ->and($subOrder->commission_amount_kobo + $subOrder->seller_payout_amount_kobo)
        ->toBe($subOrder->subtotal_kobo);
});

it('goes through the ordinary payment path once the order exists', function () {
    $purchase = ($this->agree)();
    $order = $this->checkout->build($purchase, $this->buyer, $this->address, DeliveryMethod::BuyerPickup);

    app(PaymentProcessor::class)->markPaid($order, 'paystack', 'evt-negotiated');

    $order->refresh();
    $subOrder = $order->subOrders()->sole();

    expect($order->isPaid())->toBeTrue()
        // Escrow, commission, the lot — a negotiated order is an ordinary one
        // that happened to start with an argument.
        ->and(app(WalletService::class)->heldBalance($this->seller->user))
        ->toBe($subOrder->sellerCreditKobo())
        ->and($this->product->fresh()->stock_quantity)->toBe(40);
});

// ---------------------------------------------------------------------------
// The reservation
// ---------------------------------------------------------------------------

it('holds the stock back the moment the offer is accepted', function () {
    expect($this->product->availableStock())->toBe(50);

    $purchase = ($this->agree)(qty: 10);

    $product = $this->product->fresh();

    expect($purchase->reserved_quantity)->toBe(10)
        ->and($product->reserved_quantity)->toBe(10)
        // The seller still has fifty in the shed; ten of them are spoken for.
        ->and($product->stock_quantity)->toBe(50)
        ->and($product->availableStock())->toBe(40);
});

it('keeps reserved stock out of everybody else\'s cart', function () {
    ($this->agree)(qty: 48);

    $other = User::factory()->create();
    $cart = app(CartService::class);

    $cart->add($this->product->fresh(), 10, null, $other);

    // Only two were left unreserved, so two is what they get.
    expect($cart->lines($other)->first()->quantity)->toBe(2);
});

it('gives the stock back when nobody uses the link', function () {
    $purchase = ($this->agree)(qty: 10);

    expect($this->product->fresh()->availableStock())->toBe(40);

    $purchase->forceFill(['expires_at' => now()->subHour()])->save();

    expect($this->checkout->releaseLapsedReservations())->toBe(1)
        ->and($this->product->fresh()->availableStock())->toBe(50)
        ->and($purchase->fresh()->reserved_quantity)->toBe(0);
});

it('does not put the goods back on sale while the buyer is on the payment page', function () {
    $purchase = ($this->agree)(qty: 10);

    $this->checkout->build($purchase, $this->buyer, $this->address, DeliveryMethod::BuyerPickup);

    $product = $this->product->fresh();

    // The reservation is spent, but the stock has not been decremented yet —
    // that happens when the money clears. Available stock is unchanged from
    // the buyer's point of view.
    expect($product->reserved_quantity)->toBe(0)
        ->and($product->stock_quantity)->toBe(50)
        ->and($purchase->fresh()->used_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// The window
// ---------------------------------------------------------------------------

it('holds the price for the configured window', function () {
    $this->settings->set('negotiated_checkout_hours', '6', 'int', 'platform');

    $purchase = ($this->agree)();

    expect($purchase->expires_at->diffInHours(now()->addHours(6), absolute: true))
        ->toBeLessThan(1);
});

it('refuses a link whose window has closed', function () {
    $purchase = ($this->agree)();
    $purchase->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => $this->checkout->build(
        $purchase->fresh(),
        $this->buyer,
        $this->address,
        DeliveryMethod::BuyerPickup,
    ))->toThrow(RuntimeException::class);

    expect(Order::query()->count())->toBe(0);
});

it('refuses a link somebody else was sent', function () {
    $purchase = ($this->agree)();

    expect(fn () => $this->checkout->build(
        $purchase,
        User::factory()->create(),
        $this->address,
        DeliveryMethod::BuyerPickup,
    ))->toThrow(RuntimeException::class);
});

it('will not let one link be spent twice', function () {
    $purchase = ($this->agree)();

    $this->checkout->build($purchase, $this->buyer, $this->address, DeliveryMethod::BuyerPickup);

    expect(fn () => $this->checkout->build(
        $purchase->fresh(),
        $this->buyer,
        $this->address,
        DeliveryMethod::BuyerPickup,
    ))->toThrow(RuntimeException::class);

    expect(Order::query()->count())->toBe(1);
});

it('refuses a delivery method the seller does not offer to that state', function () {
    $purchase = ($this->agree)();

    expect(fn () => $this->checkout->build(
        $purchase,
        $this->buyer,
        [...$this->address, 'state' => 'Ogun'],
        DeliveryMethod::SellerArranged,
    ))->toThrow(RuntimeException::class);
});

it('tells the buyer when the seller has sold the goods out from under them', function () {
    $purchase = ($this->agree)(qty: 10);

    // The reservation held ten; the seller's remaining forty then went.
    $this->product->fresh()->forceFill(['stock_quantity' => 5])->save();

    expect(fn () => $this->checkout->build(
        $purchase->fresh(),
        $this->buyer,
        $this->address,
        DeliveryMethod::BuyerPickup,
    ))->toThrow(RuntimeException::class);
});

it('reaches the private checkout only as the buyer it belongs to', function () {
    $purchase = ($this->agree)();

    $this->actingAs($this->buyer)
        ->get(route('negotiated.show', $purchase->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Negotiated/Show')
            ->where('purchase.unit_price', '₦17,000')
            ->where('purchase.saving', '₦30,000'));

    $this->actingAs(User::factory()->create())
        ->get(route('negotiated.show', $purchase->token))
        ->assertForbidden();
});
