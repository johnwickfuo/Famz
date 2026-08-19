<?php

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\SubOrderStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Orders\OrderBuilder;
use App\Services\Settings\SettingsService;
use App\Support\Commission;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * A cart holding three sellers' goods becomes one payment and three sub-orders,
 * each with its own commission split. If this is wrong, every seller is paid
 * the wrong amount.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->cart = app(CartService::class);
    $this->builder = app(OrderBuilder::class);

    $this->category = Category::factory()->create();
    $this->buyer = User::factory()->create();

    $this->makeSeller = function (string $state = 'Oyo', ?int $deliveryFee = 500_000): SellerProfile {
        $seller = SellerProfile::factory()->approved()->create(['state' => $state]);

        if ($deliveryFee !== null) {
            // Through the relation: seller_id is deliberately not mass-assignable.
            $seller->deliveryRates()->create([
                'state' => 'Lagos',
                'fee_kobo' => $deliveryFee,
                'is_active' => true,
            ]);
        }

        return $seller;
    };

    $this->address = [
        'name' => 'Aisha Bello',
        'phone' => '08030000000',
        'address' => '14 Taiwo Road',
        'state' => 'Lagos',
        'lga' => 'Kosofe',
    ];
});

function productFor(SellerProfile $seller, int $priceKobo, $category): Product
{
    return Product::factory()->for($seller, 'seller')->pricedAt($priceKobo)->create([
        'category_id' => $category->id,
        'stock_quantity' => 500,
        'min_order_quantity' => 1,
    ]);
}

it('splits a multi-seller cart into one sub-order per seller', function () {
    $alice = ($this->makeSeller)();
    $bob = ($this->makeSeller)();
    $chidi = ($this->makeSeller)();

    $this->cart->add(productFor($alice, 1_850_000, $this->category), 2, null, $this->buyer);
    $this->cart->add(productFor($bob, 700_000, $this->category), 3, null, $this->buyer);
    $this->cart->add(productFor($chidi, 250_000, $this->category), 1, null, $this->buyer);

    $order = $this->builder->build($this->buyer, $this->address, [
        $alice->id => DeliveryMethod::BuyerPickup->value,
        $bob->id => DeliveryMethod::BuyerPickup->value,
        $chidi->id => DeliveryMethod::BuyerPickup->value,
    ]);

    expect($order->subOrders)->toHaveCount(3)
        ->and($order->subOrders->pluck('seller_id')->sort()->values()->all())
        ->toBe(collect([$alice->id, $bob->id, $chidi->id])->sort()->values()->all());

    // One payment envelope holding all three.
    expect($order->subtotal_kobo)->toBe(2 * 1_850_000 + 3 * 700_000 + 250_000)
        ->and($order->grand_total_kobo)->toBe($order->subtotal_kobo + $order->delivery_total_kobo);
});

it('puts each seller\'s own items on their own sub-order and nobody else\'s', function () {
    $alice = ($this->makeSeller)();
    $bob = ($this->makeSeller)();

    $aliceProduct = productFor($alice, 1_000_000, $this->category);
    $bobProduct = productFor($bob, 2_000_000, $this->category);

    $this->cart->add($aliceProduct, 2, null, $this->buyer);
    $this->cart->add($bobProduct, 1, null, $this->buyer);

    $order = $this->builder->build($this->buyer, $this->address, [
        $alice->id => DeliveryMethod::BuyerPickup->value,
        $bob->id => DeliveryMethod::BuyerPickup->value,
    ]);

    $aliceSub = $order->subOrders->firstWhere('seller_id', $alice->id);
    $bobSub = $order->subOrders->firstWhere('seller_id', $bob->id);

    expect($aliceSub->items->pluck('product_id')->all())->toBe([$aliceProduct->id])
        ->and($bobSub->items->pluck('product_id')->all())->toBe([$bobProduct->id])
        ->and($aliceSub->subtotal_kobo)->toBe(2_000_000)
        ->and($bobSub->subtotal_kobo)->toBe(2_000_000);
});

it('splits commission per sub-order at the rate in force, and every one balances', function () {
    $this->settings->set('marketplace_commission_percent', 7.5, 'float', 'platform');

    $alice = ($this->makeSeller)();
    $bob = ($this->makeSeller)();

    $this->cart->add(productFor($alice, 1_850_000, $this->category), 3, null, $this->buyer);
    $this->cart->add(productFor($bob, 333_333, $this->category), 7, null, $this->buyer);

    $order = $this->builder->build($this->buyer, $this->address, [
        $alice->id => DeliveryMethod::BuyerPickup->value,
        $bob->id => DeliveryMethod::BuyerPickup->value,
    ]);

    foreach ($order->subOrders as $subOrder) {
        $expected = Commission::on($subOrder->subtotal_kobo, 7.5);

        expect($subOrder->commission_amount_kobo)->toBe($expected->commissionKobo)
            ->and($subOrder->seller_payout_amount_kobo)->toBe($expected->payoutKobo)
            ->and($subOrder->balances())->toBeTrue()
            ->and((float) $subOrder->commission_percent_snapshot)->toBe(7.5);
    }

    // And the parts add back up to the whole.
    expect($order->subOrders->sum('subtotal_kobo'))->toBe($order->subtotal_kobo);
});

it('snapshots the commission rate so a later change cannot rewrite it', function () {
    $this->settings->set('marketplace_commission_percent', 5, 'float', 'platform');

    $seller = ($this->makeSeller)();
    $this->cart->add(productFor($seller, 1_000_000, $this->category), 1, null, $this->buyer);

    $order = $this->builder->build($this->buyer, $this->address, [
        $seller->id => DeliveryMethod::BuyerPickup->value,
    ]);

    $subOrder = $order->subOrders->first();
    $commissionAtPurchase = $subOrder->commission_amount_kobo;

    // The platform doubles its rate the next day.
    $this->settings->set('marketplace_commission_percent', 10, 'float', 'platform');

    expect($subOrder->fresh()->commission_amount_kobo)->toBe($commissionAtPurchase)
        ->and((float) $subOrder->fresh()->commission_percent_snapshot)->toBe(5.0);
});

it('snapshots the item so a rename or a deletion cannot change the receipt', function () {
    $seller = ($this->makeSeller)();
    $product = productFor($seller, 1_850_000, $this->category);
    $product->update(['name' => 'Layers mash, 25kg']);

    $this->cart->add($product, 2, null, $this->buyer);

    $order = $this->builder->build($this->buyer, $this->address, [
        $seller->id => DeliveryMethod::BuyerPickup->value,
    ]);

    $item = $order->subOrders->first()->items->first();

    expect($item->product_name)->toBe('Layers mash, 25kg')
        ->and($item->unit_price_kobo)->toBe(1_850_000)
        ->and($item->quantity)->toBe(2)
        ->and($item->line_total_kobo)->toBe(3_700_000);

    // The seller renames and reprices; the receipt does not move.
    $product->update(['name' => 'Something else entirely', 'price_kobo' => 9_999_999]);

    $item->refresh();

    expect($item->product_name)->toBe('Layers mash, 25kg')
        ->and($item->unit_price_kobo)->toBe(1_850_000);

    // Even deleting the listing leaves the receipt readable.
    $product->delete();
    $item->refresh();

    expect($item->product_name)->toBe('Layers mash, 25kg')
        ->and($item->line_total_kobo)->toBe(3_700_000);
});

it('charges the seller\'s delivery rate for the buyer\'s state, per seller', function () {
    $alice = ($this->makeSeller)(deliveryFee: 500_000);
    $bob = ($this->makeSeller)(deliveryFee: 1_250_000);

    $this->cart->add(productFor($alice, 1_000_000, $this->category), 1, null, $this->buyer);
    $this->cart->add(productFor($bob, 1_000_000, $this->category), 1, null, $this->buyer);

    $order = $this->builder->build($this->buyer, $this->address, [
        $alice->id => DeliveryMethod::SellerArranged->value,
        $bob->id => DeliveryMethod::SellerArranged->value,
    ]);

    expect($order->subOrders->firstWhere('seller_id', $alice->id)->delivery_fee_kobo)->toBe(500_000)
        ->and($order->subOrders->firstWhere('seller_id', $bob->id)->delivery_fee_kobo)->toBe(1_250_000)
        ->and($order->delivery_total_kobo)->toBe(1_750_000)
        ->and($order->grand_total_kobo)->toBe($order->subtotal_kobo + 1_750_000);
});

it('charges nothing for delivery when the buyer is collecting', function () {
    $seller = ($this->makeSeller)(deliveryFee: 500_000);

    $this->cart->add(productFor($seller, 1_000_000, $this->category), 1, null, $this->buyer);

    $order = $this->builder->build($this->buyer, $this->address, [
        $seller->id => DeliveryMethod::BuyerPickup->value,
    ]);

    expect($order->subOrders->first()->delivery_fee_kobo)->toBe(0)
        ->and($order->delivery_total_kobo)->toBe(0);
});

it('refuses a delivery method the seller does not offer to that state', function () {
    // No rate set, so seller-arranged delivery is not on the table.
    $seller = ($this->makeSeller)(deliveryFee: null);

    $this->cart->add(productFor($seller, 1_000_000, $this->category), 1, null, $this->buyer);

    expect(fn () => $this->builder->build($this->buyer, $this->address, [
        $seller->id => DeliveryMethod::SellerArranged->value,
    ]))->toThrow(RuntimeException::class);
});

it('starts every sub-order waiting on its seller', function () {
    $seller = ($this->makeSeller)();
    $this->cart->add(productFor($seller, 1_000_000, $this->category), 1, null, $this->buyer);

    $order = $this->builder->build($this->buyer, $this->address, [
        $seller->id => DeliveryMethod::BuyerPickup->value,
    ]);

    expect($order->subOrders->first()->status)->toBe(SubOrderStatus::Pending)
        ->and($order->status)->toBe(OrderStatus::PendingPayment);
});

it('will not build an order from an empty cart', function () {
    expect(fn () => $this->builder->build($this->buyer, $this->address, []))
        ->toThrow(RuntimeException::class);
});

it('prices at checkout rather than trusting what the cart remembered', function () {
    $seller = ($this->makeSeller)();
    $product = productFor($seller, 1_000_000, $this->category);

    $this->cart->add($product, 2, null, $this->buyer);

    // The seller raises the price while the buyer is still shopping.
    $product->update(['price_kobo' => 1_500_000]);

    $order = $this->builder->build($this->buyer, $this->address, [
        $seller->id => DeliveryMethod::BuyerPickup->value,
    ]);

    expect($order->subtotal_kobo)->toBe(3_000_000)
        ->and($order->subOrders->first()->items->first()->unit_price_kobo)->toBe(1_500_000);
});
