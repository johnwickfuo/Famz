<?php

use App\Enums\LedgerType;
use App\Enums\RoleName;
use App\Enums\SubOrderStatus;
use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentProcessor;
use App\Services\Settlement\EscrowDriver;
use App\Services\Wallet\WalletService;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

/**
 * The seller's side of an order.
 *
 * Two things matter here beyond the happy path: a seller must never see or
 * touch another seller's part of an order, and rejecting must put the buyer's
 * money back rather than simply changing a label.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(CategorySeeder::class);
    $this->seed(SettingsSeeder::class);

    // These resources live in the seller panel; without saying so, Filament
    // resolves their routes against the default (admin) panel.
    Filament::setCurrentPanel('seller');

    settings()->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');

    $this->wallet = app(WalletService::class);

    $this->makeSeller = function (): array {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Seller->value);
        $seller = SellerProfile::factory()->approved()->create(['user_id' => $user->id]);

        return [$user, $seller->fresh()];
    };

    [$this->aliceUser, $this->alice] = ($this->makeSeller)();
    [$this->bobUser, $this->bob] = ($this->makeSeller)();

    // One paid order holding a part for each of them.
    $this->order = Order::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->partFor = function (SellerProfile $seller, int $subtotalKobo = 2_000_000): SubOrder {
        return SubOrder::factory()
            ->pricedAt($subtotalKobo, 5)
            ->create(['order_id' => $this->order->id, 'seller_id' => $seller->id]);
    };

    $this->alicePart = ($this->partFor)($this->alice);
    $this->bobPart = ($this->partFor)($this->bob);

    $this->order->forceFill([
        'subtotal_kobo' => 4_000_000,
        'grand_total_kobo' => 4_000_000,
    ])->save();

    app(PaymentProcessor::class)->markPaid($this->order->fresh(), 'paystack', 'evt-seller-test');

    $this->alicePart->refresh();
    $this->bobPart->refresh();
});

// ---------------------------------------------------------------------------
// Scoping
// ---------------------------------------------------------------------------

it('shows a seller only their own part of a shared order', function () {
    $this->actingAs($this->aliceUser);

    livewire(ListSubOrders::class)
        ->assertCanSeeTableRecords([$this->alicePart])
        ->assertCanNotSeeTableRecords([$this->bobPart]);
});

it('refuses a seller the fulfilment actions on another seller\'s part', function () {
    $this->actingAs($this->aliceUser);

    expect($this->aliceUser->can('fulfil', $this->alicePart))->toBeTrue()
        ->and($this->aliceUser->can('fulfil', $this->bobPart))->toBeFalse();

    // And the record is not reachable through the panel at all.
    $this->get('/seller/sub-orders/'.$this->bobPart->getRouteKey())->assertNotFound();
});

it('hides unpaid orders from the seller entirely', function () {
    $unpaid = Order::factory()->create(['user_id' => User::factory()->create()->id]);
    $part = SubOrder::factory()->create(['order_id' => $unpaid->id, 'seller_id' => $this->alice->id]);

    $this->actingAs($this->aliceUser);

    livewire(ListSubOrders::class)->assertCanNotSeeTableRecords([$part]);
});

// ---------------------------------------------------------------------------
// Working an order
// ---------------------------------------------------------------------------

it('walks an order from accepted to delivered', function () {
    $this->actingAs($this->aliceUser);

    livewire(ListSubOrders::class)
        ->callAction(TestAction::make('acceptOrder')->table($this->alicePart));

    expect($this->alicePart->fresh()->status)->toBe(SubOrderStatus::Accepted);

    livewire(ListSubOrders::class)
        ->callAction(TestAction::make('markShipped')->table($this->alicePart->fresh()));

    expect($this->alicePart->fresh()->status)->toBe(SubOrderStatus::Shipped);

    livewire(ListSubOrders::class)
        ->callAction(TestAction::make('markDelivered')->table($this->alicePart->fresh()));

    $part = $this->alicePart->fresh();

    expect($part->status)->toBe(SubOrderStatus::Delivered)
        ->and($part->delivered_at)->not->toBeNull()
        // Marking delivered starts the escrow clock; it does not stop it.
        ->and($part->auto_release_at)->not->toBeNull()
        ->and($this->wallet->availableBalance($this->aliceUser))->toBe(0)
        ->and($this->wallet->heldBalance($this->aliceUser))->toBe(1_900_000);
});

it('will not take a rejection without a reason', function () {
    $this->actingAs($this->aliceUser);

    livewire(ListSubOrders::class)
        ->callAction(TestAction::make('rejectOrder')->table($this->alicePart), ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($this->alicePart->fresh()->status)->toBe(SubOrderStatus::Pending);
});

it('reverses the money and restores stock when a seller rejects', function () {
    $product = Product::factory()->for($this->alice, 'seller')->create(['stock_quantity' => 10]);

    $part = ($this->partFor)($this->alice, 1_000_000);
    $part->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_of_measure' => 'bag',
        'quantity' => 3,
        'unit_price_kobo' => 333_334,
        'line_total_kobo' => 1_000_000,
    ]);

    app(EscrowDriver::class)->recordSale($part);
    $product->decrement('stock_quantity', 3);

    expect($this->wallet->heldBalance($this->aliceUser))->toBe(1_900_000 + 950_000);

    $this->actingAs($this->aliceUser);

    livewire(ListSubOrders::class)->callAction(
        TestAction::make('rejectOrder')->table($part),
        ['reason' => 'The birds did not survive the heat this week.'],
    );

    $part->refresh();

    expect($part->status)->toBe(SubOrderStatus::Rejected)
        ->and($part->rejection_reason)->toContain('did not survive')
        // The held sale is cancelled, not paid.
        ->and($this->wallet->heldBalance($this->aliceUser))->toBe(1_900_000)
        ->and($this->wallet->availableBalance($this->aliceUser))->toBe(0)
        // Stock comes back.
        ->and($product->fresh()->stock_quantity)->toBe(10)
        // And the buyer's refund is on the record.
        ->and(WalletTransaction::query()
            ->where('sub_order_id', $part->id)
            ->where('type', LedgerType::Refund)
            ->exists())->toBeTrue();
});

it('cannot accept the same order twice', function () {
    $this->actingAs($this->aliceUser);

    livewire(ListSubOrders::class)
        ->callAction(TestAction::make('acceptOrder')->table($this->alicePart));

    $accepted = $this->alicePart->fresh();

    // The action is gone once it no longer applies, rather than silently
    // running again and moving timestamps about.
    livewire(ListSubOrders::class)
        ->assertActionHidden(TestAction::make('acceptOrder')->table($accepted));
});
