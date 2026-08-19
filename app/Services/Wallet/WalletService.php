<?php

namespace App\Services\Wallet;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reading and writing the ledger.
 *
 * Every balance here is a SUM over wallet_transactions computed at the moment
 * it is asked for. Nothing caches a balance and nothing stores one, which is
 * the single design decision that keeps this layer trustworthy: there is no
 * second number that can disagree with the entries.
 *
 * A null user means the platform's own account.
 */
class WalletService
{
    /**
     * Money the account can actually use.
     *
     * Released and withdrawn both count. A withdrawal is written as a negative
     * entry, and it has to keep reducing the balance after it has been paid
     * out — otherwise the money would reappear.
     */
    public function availableBalance(User|int|null $user): int
    {
        return $this->sum($user, fn (Builder $query) => $query->whereIn('state', LedgerState::spendable()));
    }

    /**
     * Money owed but not yet theirs: escrow.
     */
    public function heldBalance(User|int|null $user): int
    {
        return $this->sum($user, fn (Builder $query) => $query->where('state', LedgerState::Held));
    }

    /**
     * Everything ever earned, whether or not it has since been withdrawn.
     *
     * Only positive entries of the earning types count: a reversal or a
     * withdrawal is not a negative earning, it is a separate event, and netting
     * them off here would understate what somebody has actually made.
     */
    public function lifetimeEarnings(User|int|null $user): int
    {
        return $this->sum($user, fn (Builder $query) => $query
            ->whereIn('type', [LedgerType::Sale, LedgerType::MentorshipEarning])
            ->whereIn('state', [LedgerState::Held, LedgerState::Released, LedgerState::Withdrawn])
            ->where('amount_kobo', '>', 0));
    }

    /**
     * Available plus held: what the account is worth if everything in escrow
     * eventually clears.
     */
    public function totalBalance(User|int|null $user): int
    {
        return $this->availableBalance($user) + $this->heldBalance($user);
    }

    /**
     * The platform's commission revenue.
     */
    public function platformEarnings(): int
    {
        return WalletTransaction::query()
            ->platform()
            ->where('type', LedgerType::Commission)
            ->whereIn('state', LedgerState::spendable())
            ->sum('amount_kobo');
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        User|int|null $user,
        LedgerType $type,
        int $amountKobo,
        LedgerState $state,
        string $description,
        ?SubOrder $subOrder = null,
        array $meta = [],
        User|int|null $createdBy = null,
    ): WalletTransaction {
        if ($amountKobo === 0) {
            // A zero entry is either a bug or noise, and it makes the ledger
            // harder to read either way.
            throw new InvalidArgumentException('A ledger entry of zero has nothing to record.');
        }

        return WalletTransaction::query()->create([
            'user_id' => $user instanceof User ? $user->getKey() : $user,
            'sub_order_id' => $subOrder?->getKey(),
            'type' => $type,
            'amount_kobo' => $amountKobo,
            'state' => $state,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
            'created_by' => $createdBy instanceof User ? $createdBy->getKey() : $createdBy,
        ]);
    }

    /**
     * Move entries from held to released.
     *
     * Returns how many moved, so a caller can tell the difference between
     * "released" and "there was nothing to release" — which matters when a
     * release is triggered twice.
     *
     * @param  array<int, LedgerType>  $types
     */
    public function releaseHeld(SubOrder $subOrder, array $types = [LedgerType::Sale, LedgerType::Commission]): int
    {
        return WalletTransaction::query()
            ->where('sub_order_id', $subOrder->getKey())
            ->where('state', LedgerState::Held)
            ->whereIn('type', $types)
            ->update(['state' => LedgerState::Released, 'updated_at' => now()]);
    }

    /**
     * Cancel the entries for a sub-order.
     *
     * Anything still held is marked refunded — it never counted toward a
     * balance, so nothing needs undoing and there is nothing to correct.
     *
     * Anything already released gets an opposite reversal entry placed beside
     * it, and the original is left untouched. The two sum to zero, which is the
     * point: the balance is corrected without any claim that the money was
     * never released.
     *
     * @return array{refunded: int, reversed: int}
     */
    public function reverseForSubOrder(SubOrder $subOrder, string $reason, User|int|null $createdBy = null): array
    {
        $entries = WalletTransaction::query()
            ->where('sub_order_id', $subOrder->getKey())
            ->whereIn('type', [LedgerType::Sale, LedgerType::Commission])
            ->whereIn('state', [LedgerState::Held, LedgerState::Released])
            ->get();

        $refunded = 0;
        $reversed = 0;

        foreach ($entries as $entry) {
            if ($entry->state === LedgerState::Held) {
                $entry->forceFill(['state' => LedgerState::Refunded])->save();
                $refunded++;

                continue;
            }

            /*
             * The original entry is left exactly as it is and a correcting
             * entry is added beside it. Voiding it *and* writing the reversal
             * would cancel the same money twice and drive the balance negative
             * — and rewriting a released entry would destroy the evidence that
             * the money was ever released, which is the thing a ledger exists
             * to prove.
             */
            $this->record(
                user: $entry->user_id,
                type: LedgerType::Reversal,
                amountKobo: -$entry->amount_kobo,
                state: LedgerState::Released,
                description: __('Reversal: :reason', ['reason' => $reason]),
                subOrder: $subOrder,
                meta: ['reverses_transaction_id' => $entry->getKey(), 'original_type' => $entry->type->value],
                createdBy: $createdBy,
            );

            $reversed++;
        }

        return ['refunded' => $refunded, 'reversed' => $reversed];
    }

    /**
     * The entries for one account, newest first.
     *
     * @return Collection<int, WalletTransaction>
     */
    public function statement(User|int|null $user, int $limit = 100): Collection
    {
        return WalletTransaction::query()
            ->forUser($user)
            ->with('subOrder')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    private function sum(User|int|null $user, callable $constrain): int
    {
        $query = WalletTransaction::query()->forUser($user);

        $constrain($query);

        return (int) $query->sum('amount_kobo');
    }
}
