<?php

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\SubOrderStatus;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Disputes\DisputeService;
use App\Services\Orders\FulfilmentService;
use App\Services\Settings\SettingsService;
use App\Services\Settlement\EscrowDriver;
use App\Services\Settlement\SettlementManager;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * Disputes, and above all the arithmetic of settling one.
 *
 * Every resolution is checked against a single law: money is neither created
 * nor destroyed. For a sub-order the buyer paid G for,
 *
 *     what the seller keeps + what the platform keeps + what goes back = G
 *
 * A path that leaves a few naira unaccounted for is not a rounding problem,
 * it is a hole, and the assertion below is written so it cannot pass with one.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->wallet = app(WalletService::class);
    $this->settlement = app(SettlementManager::class);
    $this->fulfilment = app(FulfilmentService::class);
    $this->disputes = app(DisputeService::class);

    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');

    $this->admin = User::factory()->create();
    $this->buyer = User::factory()->create();

    $this->order = Order::factory()->paid()->create(['user_id' => $this->buyer->id]);

    // ₦20,000 of goods at 5%, plus ₦3,000 delivery: ₦23,000 paid in total,
    // of which ₦1,000 is commission and ₦22,000 is the seller's.
    $this->subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create([
        'order_id' => $this->order->id,
        'delivery_fee_kobo' => 300_000,
        'status' => SubOrderStatus::Delivered,
        'delivered_at' => now()->subDay(),
        'auto_release_at' => now()->addDays(6),
    ]);

    $this->sellerUser = $this->subOrder->seller->user;

    $this->settlement->driver()->recordSale($this->subOrder);

    $this->raise = fn (): Dispute => $this->disputes->raise(
        $this->subOrder->fresh(),
        $this->buyer,
        DisputeReason::QuantityShort,
        'Two of the ten bags were half empty.',
    );
});

/**
 * The conservation law, asserted over the real ledger.
 *
 * Everything either side ends up holding, plus everything sent back to the
 * buyer, has to equal what the buyer paid — no more, no less.
 */
function assertMoneyIsConserved(SubOrder $subOrder, User $seller): void
{
    // Scoped to this sub-order, not to the accounts: a seller with two orders
    // running would otherwise let one order's shortfall hide inside another's
    // balance.
    $countingStates = [LedgerState::Held, LedgerState::Released, LedgerState::Withdrawn];

    $forThisOrder = fn (?int $userId, array $states, array $types) => (int) WalletTransaction::query()
        ->where('sub_order_id', $subOrder->getKey())
        ->when($userId === null, fn ($q) => $q->whereNull('user_id'), fn ($q) => $q->where('user_id', $userId))
        ->whereIn('state', $states)
        ->whereIn('type', $types)
        ->sum('amount_kobo');

    $everything = LedgerType::cases();

    $sellerKept = $forThisOrder($seller->getKey(), $countingStates, $everything);
    $platformKept = $forThisOrder(null, $countingStates, $everything);

    $refunded = (int) WalletTransaction::query()
        ->where('sub_order_id', $subOrder->getKey())
        ->where('type', LedgerType::Refund)
        ->sum('amount_kobo');

    expect($sellerKept + $platformKept + $refunded)
        ->toBe($subOrder->grandTotalKobo(), 'money was created or destroyed settling this dispute');
}

// ---------------------------------------------------------------------------
// Raising
// ---------------------------------------------------------------------------

it('freezes the money and blocks auto-release when a buyer disputes', function () {
    // Before: the escrow clock is running.
    expect($this->subOrder->fresh()->auto_release_at)->not->toBeNull();

    ($this->raise)();

    $subOrder = $this->subOrder->fresh();

    expect($subOrder->status)->toBe(SubOrderStatus::Disputed)
        ->and($subOrder->disputed_at)->not->toBeNull()
        ->and($subOrder->auto_release_at)->toBeNull()
        // Still held, and still nobody's to spend.
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(2_200_000)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(0);
});

it('will not auto-release a disputed order however long it waits', function () {
    ($this->raise)();

    // Force the window open: even a sub-order whose timestamp says it is due
    // must not release while a dispute is live.
    $this->subOrder->fresh()->forceFill(['auto_release_at' => now()->subMonth()])->save();

    expect($this->fulfilment->releaseDueEscrow())->toBe(0)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(0)
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(2_200_000);
});

it('refuses a second dispute while one is still open', function () {
    ($this->raise)();

    expect(fn () => ($this->raise)())->toThrow(RuntimeException::class);
    expect(Dispute::query()->count())->toBe(1);
});

it('closes the window a configurable number of days after delivery', function () {
    $this->settings->set('dispute_window_days', '3', 'int', 'platform');

    $inTime = SubOrder::factory()->create([
        'order_id' => $this->order->id,
        'status' => SubOrderStatus::Delivered,
        'delivered_at' => now()->subDays(2),
    ]);

    $tooLate = SubOrder::factory()->create([
        'order_id' => $this->order->id,
        'status' => SubOrderStatus::Delivered,
        'delivered_at' => now()->subDays(4),
    ]);

    expect($this->disputes->canRaise($inTime, $this->buyer))->toBeTrue()
        ->and($this->disputes->canRaise($tooLate, $this->buyer))->toBeFalse();
});

it('lets a buyer dispute an order that never arrived, however long ago', function () {
    $never = SubOrder::factory()->create([
        'order_id' => $this->order->id,
        'status' => SubOrderStatus::Shipped,
        'delivered_at' => null,
        'created_at' => now()->subMonths(2),
    ]);

    // The complaint is that it has not come; a deadline counted from a
    // delivery that never happened would be a deadline that never starts.
    expect($this->disputes->canRaise($never, $this->buyer))->toBeTrue();
});

it('lets nobody but the buyer dispute their order', function () {
    expect($this->disputes->canRaise($this->subOrder, $this->sellerUser))->toBeFalse()
        ->and($this->disputes->canRaise($this->subOrder, User::factory()->create()))->toBeFalse();
});

it('opens the thread with the buyer\'s own words', function () {
    $dispute = ($this->raise)();

    expect($dispute->messages)->toHaveCount(1)
        ->and($dispute->messages->first()->body)->toBe('Two of the ten bags were half empty.')
        ->and($dispute->messages->first()->user_id)->toBe($this->buyer->id);
});

// ---------------------------------------------------------------------------
// Resolutions
// ---------------------------------------------------------------------------

it('releases everything to the seller when the dispute is decided their way', function () {
    $dispute = ($this->raise)();

    $this->disputes->resolveForSeller($dispute, $this->admin, 'The photographs show full bags.');

    expect($this->wallet->availableBalance($this->sellerUser))->toBe(2_200_000)
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(0)
        ->and($this->wallet->availableBalance(null))->toBe(100_000)
        ->and($dispute->fresh()->status)->toBe(DisputeStatus::ResolvedSeller)
        ->and($dispute->fresh()->refund_amount_kobo)->toBe(0)
        ->and($this->subOrder->fresh()->status)->toBe(SubOrderStatus::Settled);

    assertMoneyIsConserved($this->subOrder->fresh(), $this->sellerUser);
});

it('returns everything to the buyer when the dispute is decided their way', function () {
    $dispute = ($this->raise)();

    $this->disputes->resolveForBuyer($dispute, $this->admin, 'The bags were short and the seller agrees.');

    expect($this->wallet->availableBalance($this->sellerUser))->toBe(0)
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(0)
        // The platform gives its commission back too. A cut of an order that
        // was refunded is not revenue.
        ->and($this->wallet->availableBalance(null))->toBe(0)
        ->and($this->wallet->heldBalance(null))->toBe(0)
        ->and($dispute->fresh()->refund_amount_kobo)->toBe(2_300_000)
        ->and($this->subOrder->fresh()->status)->toBe(SubOrderStatus::Refunded);

    assertMoneyIsConserved($this->subOrder->fresh(), $this->sellerUser);
});

it('splits the difference on a partial refund, taking the commission down with it', function () {
    $dispute = ($this->raise)();

    // ₦4,600 back — two bags out of ten, delivery kept.
    $this->disputes->resolvePartially($dispute, $this->admin, 460_000, 'Two bags short of ten.');

    $sellerKept = $this->wallet->availableBalance($this->sellerUser);
    $platformKept = $this->wallet->availableBalance(null);

    expect($dispute->fresh()->status)->toBe(DisputeStatus::ResolvedPartial)
        ->and($dispute->fresh()->refund_amount_kobo)->toBe(460_000)
        // 5% of the ₦4,600 of goods refunded comes off the commission.
        ->and($platformKept)->toBe(100_000 - 23_000)
        ->and($sellerKept)->toBe(2_200_000 - (460_000 - 23_000))
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(0);

    assertMoneyIsConserved($this->subOrder->fresh(), $this->sellerUser);
});

it('stops taking commission back once the goods are fully refunded', function () {
    $dispute = ($this->raise)();

    // ₦21,000 back out of ₦23,000: all ₦20,000 of goods and ₦1,000 of the
    // ₦3,000 delivery. The platform gives up its whole cut and no more —
    // it never taxed the delivery, so it cannot refund out of it.
    $this->disputes->resolvePartially($dispute, $this->admin, 2_100_000, 'Almost all of it was unusable.');

    expect($this->wallet->availableBalance(null))->toBe(0)
        ->and($this->wallet->availableBalance($this->sellerUser))->toBe(2_200_000 - 2_000_000);

    assertMoneyIsConserved($this->subOrder->fresh(), $this->sellerUser);
});

it('refuses a partial refund that is nothing, or the whole thing', function () {
    $dispute = ($this->raise)();

    expect(fn () => $this->disputes->resolvePartially($dispute, $this->admin, 0, 'x'))
        ->toThrow(RuntimeException::class);

    expect(fn () => $this->disputes->resolvePartially($dispute->fresh(), $this->admin, 2_300_000, 'x'))
        ->toThrow(RuntimeException::class);
});

it('restarts the escrow clock when a dispute is closed without a decision', function () {
    $dispute = ($this->raise)();

    $this->disputes->closeWithoutDecision($dispute, $this->admin, 'The buyer says it was sorted out directly.');

    $subOrder = $this->subOrder->fresh();

    expect($dispute->fresh()->status)->toBe(DisputeStatus::Closed)
        ->and($subOrder->status)->toBe(SubOrderStatus::Delivered)
        ->and($subOrder->disputed_at)->toBeNull()
        // Running again, not released outright: nobody decided anything.
        ->and($subOrder->auto_release_at)->not->toBeNull()
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(2_200_000);

    assertMoneyIsConserved($subOrder, $this->sellerUser);
});

it('will not settle the same dispute twice', function () {
    $dispute = ($this->raise)();

    $this->disputes->resolveForBuyer($dispute, $this->admin, 'Refunded.');

    expect(fn () => $this->disputes->resolveForSeller($dispute->fresh(), $this->admin, 'Changed my mind.'))
        ->toThrow(RuntimeException::class);
});

it('conserves money across every resolution path', function (string $method, int $amount) {
    $dispute = ($this->raise)();

    match ($method) {
        'seller' => $this->disputes->resolveForSeller($dispute, $this->admin, 'note'),
        'buyer' => $this->disputes->resolveForBuyer($dispute, $this->admin, 'note'),
        'partial' => $this->disputes->resolvePartially($dispute, $this->admin, $amount, 'note'),
        'closed' => $this->disputes->closeWithoutDecision($dispute, $this->admin, 'note'),
    };

    assertMoneyIsConserved($this->subOrder->fresh(), $this->sellerUser);
})->with([
    ['seller', 0],
    ['buyer', 0],
    ['closed', 0],
    // Awkward amounts on purpose: anything that rounds badly shows up here.
    ['partial', 1],
    ['partial', 33_333],
    ['partial', 299_999],
    ['partial', 300_001],
    ['partial', 1_111_111],
    ['partial', 2_299_999],
]);

it('never leaves a fraction of a kobo anywhere, over many splits', function () {
    // One dispute per run would hide a bias that only shows up in aggregate.
    foreach (range(1, 300) as $ignored) {
        $order = Order::factory()->paid()->create(['user_id' => $this->buyer->id]);

        $subtotal = random_int(10_000, 5_000_000);
        $delivery = random_int(0, 400_000);

        $subOrder = SubOrder::factory()->pricedAt($subtotal, 5)->create([
            'order_id' => $order->id,
            'delivery_fee_kobo' => $delivery,
            'status' => SubOrderStatus::Delivered,
            'delivered_at' => now()->subHour(),
        ]);

        $this->settlement->driver()->recordSale($subOrder);

        $dispute = $this->disputes->raise(
            $subOrder->fresh(),
            $this->buyer,
            DisputeReason::QualityPoor,
            'Not as described.',
        );

        $stake = $subOrder->grandTotalKobo();
        $refund = random_int(1, max(1, $stake - 1));

        $this->disputes->resolvePartially($dispute, $this->admin, $refund, 'Split.');

        assertMoneyIsConserved($subOrder->fresh(), $subOrder->seller->user);
    }
});

it('records the refund in a state that inflates nobody\'s balance', function () {
    $dispute = ($this->raise)();

    $this->disputes->resolveForBuyer($dispute, $this->admin, 'Refunded.');

    $refund = WalletTransaction::query()
        ->where('sub_order_id', $this->subOrder->id)
        ->where('type', LedgerType::Refund)
        ->sole();

    // The money goes back to the card, not into a wallet.
    expect($refund->state)->toBe(LedgerState::Refunded)
        ->and($this->wallet->availableBalance($this->buyer))->toBe(0)
        ->and($this->wallet->heldBalance($this->buyer))->toBe(0);
});
