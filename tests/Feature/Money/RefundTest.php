<?php

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\SubOrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Orders\FulfilmentService;
use App\Services\Settings\SettingsService;
use App\Services\Settlement\EscrowDriver;
use App\Services\Settlement\InstantDriver;
use App\Services\Settlement\SettlementManager;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * A seller rejects an order the buyer has already paid for.
 *
 * The money has to come back, the ledger has to say so, and the seller must not
 * be left holding a payout for goods they never sent.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->wallet = app(WalletService::class);
    $this->settlement = app(SettlementManager::class);
    $this->fulfilment = app(FulfilmentService::class);

    $this->buyer = User::factory()->create();
    $this->order = Order::factory()->paid()->create(['user_id' => $this->buyer->id]);

    $this->subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create([
        'order_id' => $this->order->id,
        'delivery_fee_kobo' => 300_000,
    ]);

    $this->sellerUser = $this->subOrder->seller->user;
});

it('requires a reason before a seller can reject', function () {
    expect(fn () => $this->fulfilment->reject($this->subOrder, '   '))
        ->toThrow(RuntimeException::class);

    expect($this->subOrder->fresh()->status)->toBe(SubOrderStatus::Pending);
});

it('records the reason so the buyer knows what happened', function () {
    $this->fulfilment->reject($this->subOrder, 'The last bags went this morning and no more are coming until Friday.');

    $subOrder = $this->subOrder->fresh();

    expect($subOrder->status)->toBe(SubOrderStatus::Rejected)
        ->and($subOrder->rejection_reason)->toBe('The last bags went this morning and no more are coming until Friday.')
        ->and($subOrder->rejected_at)->not->toBeNull();
});

it('cancels a held payout when the seller rejects under escrow', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    expect($this->wallet->heldBalance($this->sellerUser))->toBe(1_900_000);

    $this->fulfilment->reject($this->subOrder, 'Out of stock.');

    expect($this->wallet->heldBalance($this->sellerUser))->toBe(0)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(0)
        // The platform's commission goes with it: no delivery, no commission.
        ->and($this->wallet->heldBalance(null))->toBe(0)
        ->and($this->wallet->availableBalance(null))->toBe(0);
});

it('reverses an already-released payout when the seller rejects under instant settlement', function () {
    $this->settings->set('settlement_driver', InstantDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    expect($this->wallet->availableBalance($this->sellerUser))->toBe(1_900_000);

    $this->fulfilment->reject($this->subOrder, 'Cannot fulfil.');

    expect($this->wallet->availableBalance($this->sellerUser))->toBe(0)
        ->and($this->wallet->availableBalance(null))->toBe(0);

    // Corrected by a new entry, not by rewriting the old one.
    $reversals = WalletTransaction::query()->where('type', LedgerType::Reversal)->get();

    expect($reversals)->toHaveCount(2)
        ->and($reversals->sum('amount_kobo'))->toBe(-2_000_000);
});

it('writes a refund entry recording what the buyer is owed', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->reject($this->subOrder, 'Cannot fulfil.');

    $refund = WalletTransaction::query()->where('type', LedgerType::Refund)->sole();

    expect($refund->user_id)->toBe($this->buyer->id)
        // The whole of what they paid for this seller's part, delivery included.
        ->and($refund->amount_kobo)->toBe(2_300_000)
        ->and($refund->sub_order_id)->toBe($this->subOrder->id)
        ->and($refund->meta['reason'])->toBe('Cannot fulfil.');
});

it('does not let the refund entry inflate anybody\'s balance', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->reject($this->subOrder, 'Cannot fulfil.');

    // The money goes back to the buyer's card, not into a wallet — so the
    // entry is a record, in a state that counts toward nothing.
    $refund = WalletTransaction::query()->where('type', LedgerType::Refund)->sole();

    expect($refund->state)->toBe(LedgerState::Refunded)
        ->and($this->wallet->availableBalance($this->buyer))->toBe(0)
        ->and($this->wallet->heldBalance($this->buyer))->toBe(0);
});

it('puts the goods back on the shelf', function () {
    $category = Category::factory()->create();
    $product = Product::factory()
        ->for($this->subOrder->seller, 'seller')
        ->create(['category_id' => $category->id, 'stock_quantity' => 10]);

    $this->subOrder->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_of_measure' => $product->unit_of_measure->value,
        'unit_price_kobo' => 1_000_000,
        'quantity' => 4,
        'line_total_kobo' => 4_000_000,
    ]);

    $this->fulfilment->reject($this->subOrder->fresh(), 'Cannot fulfil.');

    expect($product->fresh()->stock_quantity)->toBe(14);
});

it('marks the whole order refunded when its only seller rejects', function () {
    $this->fulfilment->reject($this->subOrder, 'Cannot fulfil.');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('leaves the rest of a multi-seller order alone when one seller rejects', function () {
    $other = SubOrder::factory()->pricedAt(1_000_000, 5)->create(['order_id' => $this->order->id]);

    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);
    $this->settlement->driver()->recordSale($other);

    $this->fulfilment->reject($this->subOrder, 'Cannot fulfil.');

    // One seller's failure must not touch another's money.
    expect($this->wallet->heldBalance($other->seller->user))->toBe(950_000)
        ->and($other->fresh()->status)->toBe(SubOrderStatus::Pending)
        ->and($this->order->fresh()->status)->not->toBe(OrderStatus::Refunded);
});

it('will not let a seller reject an order they have already shipped', function () {
    $this->fulfilment->accept($this->subOrder);
    $this->fulfilment->markShipped($this->subOrder->fresh());

    expect(fn () => $this->fulfilment->reject($this->subOrder->fresh(), 'Changed my mind.'))
        ->toThrow(RuntimeException::class);
});

it('will not let a rejected order be rejected twice', function () {
    $this->fulfilment->reject($this->subOrder, 'Cannot fulfil.');

    expect(fn () => $this->fulfilment->reject($this->subOrder->fresh(), 'Still cannot.'))
        ->toThrow(RuntimeException::class);

    // And the refund was written once.
    expect(WalletTransaction::query()->where('type', LedgerType::Refund)->count())->toBe(1);
});
