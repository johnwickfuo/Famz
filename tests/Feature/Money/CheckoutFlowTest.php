<?php

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\SubOrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Payments\PaymentProcessor;
use App\Services\Settlement\EscrowDriver;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;

/**
 * The buyer's route through the money layer, over HTTP.
 *
 * The service tests prove the arithmetic; these prove the screens in front of
 * it cannot be walked around — that the callback grants nothing, that one
 * buyer cannot read another's order, and that "I have received this" is the
 * only thing that hands a seller their money early.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config()->set('services.paystack.secret_key', 'sk_test_paystack');

    $this->cart = app(CartService::class);
    $this->category = Category::factory()->create();
    $this->buyer = User::factory()->create();

    $this->seller = SellerProfile::factory()->approved()->create(['state' => 'Oyo']);
    $this->seller->deliveryRates()->create([
        'state' => 'Lagos',
        'fee_kobo' => 500_000,
        'is_active' => true,
    ]);

    // ₦18,500 a bag.
    $this->product = Product::factory()->for($this->seller, 'seller')->pricedAt(1_850_000)->create([
        'category_id' => $this->category->id,
        'stock_quantity' => 100,
        'min_order_quantity' => 1,
    ]);

    $this->address = [
        'name' => 'Aisha Bello',
        'phone' => '08030000000',
        'address' => '14 Taiwo Road',
        'state' => 'Lagos',
        'lga' => 'Kosofe',
    ];

    $this->fakePaystack = function (): void {
        Http::fake(['*/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'ref',
                'authorization_url' => 'https://checkout.paystack.com/abc123',
            ],
        ])]);
    };
});

// ---------------------------------------------------------------------------
// The checkout page
// ---------------------------------------------------------------------------

it('sends an empty cart back to the cart rather than to payment', function () {
    $this->actingAs($this->buyer)
        ->get(route('checkout.show'))
        ->assertRedirect(route('cart.index'));
});

it('quotes delivery for the state the buyer has chosen', function () {
    $this->actingAs($this->buyer);
    $this->cart->add($this->product, 2);

    // Oyo: this seller has set no rate there, so only collection is on offer.
    $this->get(route('checkout.show', ['state' => 'Oyo']))
        ->assertInertia(fn ($page) => $page
            ->component('Checkout/Show')
            ->where('groups.0.delivery_methods.0.value', DeliveryMethod::BuyerPickup->value)
            ->count('groups.0.delivery_methods', 1));

    // Lagos: they deliver, at the rate they set.
    $this->get(route('checkout.show', ['state' => 'Lagos']))
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.delivery_methods.0.value', DeliveryMethod::SellerArranged->value)
            ->where('groups.0.delivery_methods.0.fee_kobo', 500_000));
});

it('never shows the buyer the platform commission', function () {
    $this->actingAs($this->buyer);
    $this->cart->add($this->product, 2);

    $response = $this->get(route('checkout.show', ['state' => 'Lagos']));

    $props = $response->viewData('page')['props'];

    expect(json_encode($props))
        ->not->toContain('commission')
        ->not->toContain('payout');
});

it('warns the buyer before payment when a price has moved', function () {
    $this->actingAs($this->buyer);
    $this->cart->add($this->product, 1);

    $this->product->forceFill(['price_kobo' => 2_100_000])->save();

    $this->get(route('checkout.show'))
        ->assertInertia(fn ($page) => $page
            ->count('priceChanges', 1)
            ->where('priceChanges.0.was', '₦18,500')
            ->where('priceChanges.0.now', '₦21,000')
            ->where('priceChanges.0.increased', true));
});

it('says so plainly when no payment provider has been set up', function () {
    config()->set('services.paystack.secret_key', null);
    config()->set('services.flutterwave.secret_key', null);

    $this->actingAs($this->buyer);
    $this->cart->add($this->product, 1);

    $this->get(route('checkout.show'))
        ->assertInertia(fn ($page) => $page->count('gateways', 0));
});

// ---------------------------------------------------------------------------
// Placing the order
// ---------------------------------------------------------------------------

it('builds the order, empties the cart and sends the buyer to the gateway', function () {
    ($this->fakePaystack)();

    $this->actingAs($this->buyer);
    $this->cart->add($this->product, 2);

    $this->post(route('checkout.store'), [
        ...$this->address,
        'delivery_methods' => [$this->seller->id => DeliveryMethod::SellerArranged->value],
        'gateway' => 'paystack',
    ])->assertRedirect('https://checkout.paystack.com/abc123');

    $order = Order::query()->where('user_id', $this->buyer->id)->sole();

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->payment_gateway)->toBe('paystack')
        // ₦37,000 of feed plus ₦5,000 to bring it.
        ->and($order->subtotal_kobo)->toBe(3_700_000)
        ->and($order->delivery_total_kobo)->toBe(500_000)
        ->and($order->grand_total_kobo)->toBe(4_200_000)
        ->and($this->cart->count())->toBe(0);
});

it('refuses a delivery method the seller does not offer for that state', function () {
    $this->actingAs($this->buyer);
    $this->cart->add($this->product, 1);

    // This seller has a Lagos rate and no Ogun rate.
    $this->post(route('checkout.store'), [
        ...$this->address,
        'state' => 'Ogun',
        'delivery_methods' => [$this->seller->id => DeliveryMethod::SellerArranged->value],
        'gateway' => 'paystack',
    ])->assertSessionHas('error');

    expect(Order::query()->count())->toBe(0);
});

it('keeps the order payable when the gateway cannot be reached', function () {
    // The gateway client retries twice of its own accord, so both of those
    // fail; the buyer's own second try, from the order page, gets through.
    Http::fake(['*/transaction/initialize' => Http::sequence()
        ->push([], 500)
        ->push([], 500)
        ->push([
            'status' => true,
            'data' => [
                'reference' => 'ref',
                'authorization_url' => 'https://checkout.paystack.com/abc123',
            ],
        ])]);

    $this->actingAs($this->buyer);
    $this->cart->add($this->product, 1);

    $this->post(route('checkout.store'), [
        ...$this->address,
        'delivery_methods' => [$this->seller->id => DeliveryMethod::BuyerPickup->value],
        'gateway' => 'paystack',
    ])->assertSessionHas('error');

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::PendingPayment);

    // …and it can be picked up again from the order page.
    $this->post(route('checkout.pay', $order))
        ->assertRedirect('https://checkout.paystack.com/abc123');
});

// ---------------------------------------------------------------------------
// Coming back from the gateway
// ---------------------------------------------------------------------------

it('grants nothing on the callback, whoever visits it', function () {
    ($this->fakePaystack)();

    $this->actingAs($this->buyer);
    $this->cart->add($this->product, 1);
    $this->post(route('checkout.store'), [
        ...$this->address,
        'delivery_methods' => [$this->seller->id => DeliveryMethod::BuyerPickup->value],
        'gateway' => 'paystack',
    ]);

    $order = Order::query()->sole();

    // Hitting the callback repeatedly, as a buyer refreshing would, moves
    // nothing: only a signed webhook does that.
    foreach (range(1, 3) as $ignored) {
        $this->get(route('checkout.callback', ['reference' => $order->reference]))
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Callback')
                ->where('order.is_paid', false));
    }

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->fresh()->paid_at)->toBeNull();
});

it('lets only the buyer poll their own order', function () {
    $order = Order::factory()->create(['user_id' => $this->buyer->id]);

    $this->actingAs($this->buyer)
        ->getJson(route('checkout.status', $order))
        ->assertOk()
        ->assertJson(['reference' => $order->reference, 'is_paid' => false]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('checkout.status', $order))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// The buyer's orders
// ---------------------------------------------------------------------------

it('shows a buyer their own orders and nobody else their orders', function () {
    $mine = Order::factory()->create(['user_id' => $this->buyer->id]);
    $theirs = Order::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($this->buyer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->component('Orders/Index')->count('orders', 1));

    $this->actingAs($this->buyer)->get(route('orders.show', $mine))->assertOk();
    $this->actingAs($this->buyer)->get(route('orders.show', $theirs))->assertForbidden();
});

it('withholds the collection address until the money is in', function () {
    $subOrder = SubOrder::factory()->pricedAt(1_850_000, 5)->create([
        'delivery_method' => DeliveryMethod::BuyerPickup,
    ]);
    $order = $subOrder->order;
    $order->forceFill(['user_id' => $this->buyer->id])->save();
    $subOrder->seller->forceFill(['address' => '3 Bodija Market Road'])->save();

    $this->actingAs($this->buyer)
        ->get(route('orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('subOrders.0.seller.address', null));

    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    $this->actingAs($this->buyer)
        ->get(route('orders.show', $order->fresh()))
        ->assertInertia(fn ($page) => $page->where('subOrders.0.seller.address', '3 Bodija Market Road'));
});

it('pays the seller when the buyer confirms receipt, and only the buyer may', function () {
    settings()->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');

    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create();
    $order = $subOrder->order;
    $order->forceFill(['user_id' => $this->buyer->id])->save();

    app(PaymentProcessor::class)->markPaid($order->fresh(), 'paystack', 'evt-1');
    $subOrder->refresh()->forceFill(['status' => SubOrderStatus::Shipped])->save();

    $wallet = app(WalletService::class);
    $sellerUser = $subOrder->seller->user;

    expect($wallet->heldBalance($sellerUser))->toBe(1_900_000)
        ->and($wallet->availableBalance($sellerUser))->toBe(0);

    // A stranger cannot release somebody else's money.
    $this->actingAs(User::factory()->create())
        ->post(route('orders.received', $subOrder))
        ->assertForbidden();

    expect($wallet->availableBalance($sellerUser))->toBe(0);

    $this->actingAs($this->buyer)
        ->post(route('orders.received', $subOrder))
        ->assertRedirect();

    expect($wallet->availableBalance($sellerUser))->toBe(1_900_000)
        ->and($wallet->heldBalance($sellerUser))->toBe(0)
        ->and($subOrder->fresh()->received_at)->not->toBeNull();
});
