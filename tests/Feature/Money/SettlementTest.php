<?php

use App\Enums\LedgerType;
use App\Enums\SubOrderStatus;
use App\Models\SubOrder;
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
 * When a seller's money becomes theirs, under both drivers.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->wallet = app(WalletService::class);
    $this->settlement = app(SettlementManager::class);
    $this->fulfilment = app(FulfilmentService::class);

    // ₦20,000 subtotal at 5%: ₦1,000 commission, ₦19,000 payout.
    $this->subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create();
    $this->sellerUser = $this->subOrder->seller->user;
});

// ---------------------------------------------------------------------------
// Escrow
// ---------------------------------------------------------------------------

it('holds both the payout and the commission on payment', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');

    $this->settlement->driver()->recordSale($this->subOrder);

    expect($this->wallet->heldBalance($this->sellerUser))->toBe(1_900_000)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(0)
        ->and($this->wallet->heldBalance(null))->toBe(100_000)
        ->and($this->wallet->availableBalance(null))->toBe(0);
});

it('records commission as its own entry against the platform', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');

    $this->settlement->driver()->recordSale($this->subOrder);

    $commission = WalletTransaction::query()
        ->where('sub_order_id', $this->subOrder->id)
        ->where('type', LedgerType::Commission)
        ->sole();

    expect($commission->user_id)->toBeNull()
        ->and($commission->amount_kobo)->toBe(100_000);

    $sale = WalletTransaction::query()
        ->where('sub_order_id', $this->subOrder->id)
        ->where('type', LedgerType::Sale)
        ->sole();

    expect($sale->user_id)->toBe($this->sellerUser->id)
        ->and($sale->amount_kobo)->toBe(1_900_000);

    // The two entries account for the whole subtotal and nothing more.
    expect($sale->amount_kobo + $commission->amount_kobo)->toBe($this->subOrder->subtotal_kobo);
});

it('releases the money when the buyer confirms receipt', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->accept($this->subOrder);
    $this->fulfilment->markShipped($this->subOrder->refresh());
    $this->fulfilment->markReceived($this->subOrder->refresh());

    expect($this->wallet->heldBalance($this->sellerUser))->toBe(0)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(1_900_000)
        ->and($this->wallet->availableBalance(null))->toBe(100_000)
        ->and($this->subOrder->fresh()->status)->toBe(SubOrderStatus::Settled)
        ->and($this->subOrder->fresh()->settled_at)->not->toBeNull();
});

it('sets an auto-release date when the seller marks delivery', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settings->set('escrow_auto_release_days', 7, 'int', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->accept($this->subOrder);
    $this->fulfilment->markDelivered($this->subOrder->refresh());

    $subOrder = $this->subOrder->fresh();

    expect($subOrder->auto_release_at)->not->toBeNull()
        ->and($subOrder->auto_release_at->isBetween(now()->addDays(6), now()->addDays(8)))->toBeTrue()
        // Nothing has moved yet: the buyer still has a week to say something.
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(1_900_000);
});

it('releases on its own once the window has passed', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->accept($this->subOrder);
    $this->fulfilment->markDelivered($this->subOrder->refresh());

    // Nothing is due yet.
    expect($this->fulfilment->releaseDueEscrow())->toBe(0)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(0);

    $this->travel(8)->days();

    expect($this->fulfilment->releaseDueEscrow())->toBe(1)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(1_900_000)
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(0);
});

it('honours a shorter auto-release window from settings', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settings->set('escrow_auto_release_days', 2, 'int', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->accept($this->subOrder);
    $this->fulfilment->markDelivered($this->subOrder->refresh());

    $this->travel(3)->days();

    expect($this->fulfilment->releaseDueEscrow())->toBe(1);
});

it('does not release while a dispute is open', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->accept($this->subOrder);
    $this->fulfilment->markDelivered($this->subOrder->refresh());
    $this->fulfilment->dispute($this->subOrder->refresh(), 'Only half the bags arrived.');

    $this->travel(30)->days();

    expect($this->fulfilment->releaseDueEscrow())->toBe(0)
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(1_900_000)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(0);
});

it('releases only once, however many times it is asked', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->accept($this->subOrder);
    $this->fulfilment->markDelivered($this->subOrder->refresh());

    $driver = $this->settlement->driver();

    expect($driver->release($this->subOrder->refresh()))->toBeTrue()
        ->and($driver->release($this->subOrder->refresh()))->toBeFalse()
        ->and($driver->release($this->subOrder->refresh()))->toBeFalse()
        // The money moved exactly once.
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(1_900_000);
});

// ---------------------------------------------------------------------------
// Instant
// ---------------------------------------------------------------------------

it('makes the money available immediately under the instant driver', function () {
    $this->settings->set('settlement_driver', InstantDriver::KEY, 'string', 'platform');

    $this->settlement->driver()->recordSale($this->subOrder);

    expect($this->wallet->availableBalance($this->sellerUser))->toBe(1_900_000)
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(0)
        ->and($this->wallet->availableBalance(null))->toBe(100_000);
});

it('still records commission separately under the instant driver', function () {
    $this->settings->set('settlement_driver', InstantDriver::KEY, 'string', 'platform');

    $this->settlement->driver()->recordSale($this->subOrder);

    // The platform's books read the same whichever driver is in force.
    expect(WalletTransaction::query()->where('type', LedgerType::Commission)->count())->toBe(1)
        ->and($this->wallet->platformEarnings())->toBe(100_000);
});

it('does not set an auto-release date under the instant driver', function () {
    $this->settings->set('settlement_driver', InstantDriver::KEY, 'string', 'platform');
    $this->settlement->driver()->recordSale($this->subOrder);

    $this->fulfilment->accept($this->subOrder);
    $this->fulfilment->markDelivered($this->subOrder->refresh());

    // There is nothing held, so there is nothing to wait for.
    expect($this->subOrder->fresh()->auto_release_at)->toBeNull()
        ->and($this->fulfilment->releaseDueEscrow())->toBe(0);
});

it('resolves the driver named in settings', function () {
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    expect($this->settlement->driver())->toBeInstanceOf(EscrowDriver::class);

    $this->settings->set('settlement_driver', InstantDriver::KEY, 'string', 'platform');
    expect($this->settlement->driver())->toBeInstanceOf(InstantDriver::class);
});

it('refuses an unknown settlement driver rather than silently picking one', function () {
    expect(fn () => $this->settlement->driver('wishful-thinking'))
        ->toThrow(InvalidArgumentException::class);
});
