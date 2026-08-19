<?php

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Schema;

/**
 * Balances are derived, never stored.
 *
 * There is no balance column anywhere in this application, which means a
 * balance cannot drift from the entries that produced it. These tests hold that
 * property directly: they compute what the ledger should say and compare it to
 * what the service says, entry by entry.
 */
beforeEach(function (): void {
    $this->wallet = app(WalletService::class);
    $this->user = User::factory()->create();
});

it('has no balance column anywhere on the ledger', function () {
    $columns = Schema::getColumnListing('wallet_transactions');

    // If somebody ever adds one, this fails and they have to justify it.
    expect($columns)->not->toContain('balance')
        ->not->toContain('running_balance')
        ->not->toContain('balance_after');
});

it('derives an available balance by summing released entries', function () {
    $this->wallet->record($this->user, LedgerType::Sale, 500_000, LedgerState::Released, 'One');
    $this->wallet->record($this->user, LedgerType::Sale, 250_000, LedgerState::Released, 'Two');

    expect($this->wallet->availableBalance($this->user))->toBe(750_000);
});

it('does not count held money as available', function () {
    $this->wallet->record($this->user, LedgerType::Sale, 500_000, LedgerState::Held, 'In escrow');

    expect($this->wallet->availableBalance($this->user))->toBe(0)
        ->and($this->wallet->heldBalance($this->user))->toBe(500_000)
        ->and($this->wallet->totalBalance($this->user))->toBe(500_000);
});

it('keeps a withdrawal deducted after it has been paid out', function () {
    $this->wallet->record($this->user, LedgerType::Sale, 1_000_000, LedgerState::Released, 'Sale');
    $withdrawal = $this->wallet->record($this->user, LedgerType::Withdrawal, -400_000, LedgerState::Released, 'Cash out');

    expect($this->wallet->availableBalance($this->user))->toBe(600_000);

    // Marking it paid must not give the money back.
    $withdrawal->forceFill(['state' => LedgerState::Withdrawn])->save();

    expect($this->wallet->availableBalance($this->user))->toBe(600_000);
});

it('ignores refunded entries entirely', function () {
    $this->wallet->record($this->user, LedgerType::Sale, 1_000_000, LedgerState::Released, 'Sale');
    $this->wallet->record($this->user, LedgerType::Sale, 300_000, LedgerState::Refunded, 'Cancelled sale');

    expect($this->wallet->availableBalance($this->user))->toBe(1_000_000);
});

it('ignores pending entries until something moves them', function () {
    $this->wallet->record($this->user, LedgerType::Sale, 900_000, LedgerState::Pending, 'Awaiting confirmation');

    expect($this->wallet->availableBalance($this->user))->toBe(0)
        ->and($this->wallet->heldBalance($this->user))->toBe(0);
});

it('counts lifetime earnings without netting off withdrawals', function () {
    $this->wallet->record($this->user, LedgerType::Sale, 1_000_000, LedgerState::Released, 'Sale one');
    $this->wallet->record($this->user, LedgerType::Sale, 500_000, LedgerState::Held, 'Sale two, in escrow');
    $this->wallet->record($this->user, LedgerType::MentorshipEarning, 200_000, LedgerState::Released, 'Consultation');
    $this->wallet->record($this->user, LedgerType::Withdrawal, -800_000, LedgerState::Withdrawn, 'Cash out');

    // Taking money out is not un-earning it.
    expect($this->wallet->lifetimeEarnings($this->user))->toBe(1_700_000)
        ->and($this->wallet->availableBalance($this->user))->toBe(400_000);
});

it('keeps one account\'s money out of another\'s', function () {
    $other = User::factory()->create();

    $this->wallet->record($this->user, LedgerType::Sale, 1_000_000, LedgerState::Released, 'Mine');
    $this->wallet->record($other, LedgerType::Sale, 5_000_000, LedgerState::Released, 'Theirs');

    expect($this->wallet->availableBalance($this->user))->toBe(1_000_000)
        ->and($this->wallet->availableBalance($other))->toBe(5_000_000);
});

it('keeps the platform account separate from every user', function () {
    $this->wallet->record($this->user, LedgerType::Sale, 1_000_000, LedgerState::Released, 'Seller payout');
    $this->wallet->record(null, LedgerType::Commission, 50_000, LedgerState::Released, 'Commission');

    expect($this->wallet->availableBalance($this->user))->toBe(1_000_000)
        ->and($this->wallet->availableBalance(null))->toBe(50_000)
        ->and($this->wallet->platformEarnings())->toBe(50_000);
});

it('refuses to write an entry of zero', function () {
    expect(fn () => $this->wallet->record($this->user, LedgerType::Adjustment, 0, LedgerState::Released, 'Nothing'))
        ->toThrow(InvalidArgumentException::class);
});

it('agrees with a hand-summed ledger over a long, mixed history', function () {
    // Balances are summed, so the way to test them is to build a history and
    // compute the answer independently.
    mt_srand(4242);

    $expectedAvailable = 0;
    $expectedHeld = 0;
    $expectedEarnings = 0;

    for ($i = 0; $i < 400; $i++) {
        $amount = mt_rand(1, 5_000_000);

        [$type, $state, $signed] = match (mt_rand(0, 4)) {
            0 => [LedgerType::Sale, LedgerState::Released, $amount],
            1 => [LedgerType::Sale, LedgerState::Held, $amount],
            2 => [LedgerType::Withdrawal, LedgerState::Withdrawn, -$amount],
            3 => [LedgerType::Adjustment, LedgerState::Released, mt_rand(0, 1) ? $amount : -$amount],
            default => [LedgerType::Sale, LedgerState::Refunded, $amount],
        };

        $this->wallet->record($this->user, $type, $signed, $state, "Entry {$i}");

        if (in_array($state, LedgerState::spendable(), true)) {
            $expectedAvailable += $signed;
        }

        if ($state === LedgerState::Held) {
            $expectedHeld += $signed;
        }

        if ($type->isEarning()
            && $signed > 0
            && in_array($state, [LedgerState::Held, LedgerState::Released, LedgerState::Withdrawn], true)
        ) {
            $expectedEarnings += $signed;
        }
    }

    expect($this->wallet->availableBalance($this->user))->toBe($expectedAvailable)
        ->and($this->wallet->heldBalance($this->user))->toBe($expectedHeld)
        ->and($this->wallet->lifetimeEarnings($this->user))->toBe($expectedEarnings);
});

it('cancels held entries without needing a reversal', function () {
    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create();
    $seller = $subOrder->seller->user;

    $this->wallet->record($seller, LedgerType::Sale, 1_900_000, LedgerState::Held, 'Sale', $subOrder);
    $this->wallet->record(null, LedgerType::Commission, 100_000, LedgerState::Held, 'Commission', $subOrder);

    $result = $this->wallet->reverseForSubOrder($subOrder, 'Seller could not fulfil');

    // Held money never counted toward a balance, so nothing needs undoing.
    expect($result)->toBe(['refunded' => 2, 'reversed' => 0])
        ->and($this->wallet->heldBalance($seller))->toBe(0)
        ->and($this->wallet->availableBalance($seller))->toBe(0)
        ->and(WalletTransaction::query()->where('type', LedgerType::Reversal)->count())->toBe(0);
});

it('reverses released entries rather than rewriting them', function () {
    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create();
    $seller = $subOrder->seller->user;

    $sale = $this->wallet->record($seller, LedgerType::Sale, 1_900_000, LedgerState::Released, 'Sale', $subOrder);
    $this->wallet->record(null, LedgerType::Commission, 100_000, LedgerState::Released, 'Commission', $subOrder);

    expect($this->wallet->availableBalance($seller))->toBe(1_900_000);

    $result = $this->wallet->reverseForSubOrder($subOrder, 'Buyer returned the goods');

    expect($result)->toBe(['refunded' => 0, 'reversed' => 2])
        ->and($this->wallet->availableBalance($seller))->toBe(0)
        ->and($this->wallet->availableBalance(null))->toBe(0);

    // The original entry is still there — a ledger that can be rewritten is not
    // evidence of anything.
    expect(WalletTransaction::query()->whereKey($sale->id)->exists())->toBeTrue();

    $reversal = WalletTransaction::query()->where('type', LedgerType::Reversal)->where('user_id', $seller->id)->sole();

    expect($reversal->amount_kobo)->toBe(-1_900_000)
        ->and($reversal->meta['reverses_transaction_id'])->toBe($sale->id);
});

it('produces a statement newest first', function () {
    $this->wallet->record($this->user, LedgerType::Sale, 100_000, LedgerState::Released, 'Oldest');
    $this->wallet->record($this->user, LedgerType::Sale, 200_000, LedgerState::Released, 'Newest');

    expect($this->wallet->statement($this->user)->pluck('description')->all())
        ->toBe(['Newest', 'Oldest']);
});
