<?php

namespace App\Services\Payouts;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\WithdrawalStatus;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Payments\Data\TransferRequest;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Money leaving a wallet.
 *
 * The one thing this class exists to prevent is paying the same naira twice.
 * A balance is a sum over the ledger, so two requests arriving together would
 * both read the same balance and both be allowed — unless the read is a
 * locking one. `requestFor()` therefore takes a row lock on the ledger and on
 * the user's outstanding withdrawals before it decides anything, and everything
 * downstream trusts that decision.
 *
 * A request reserves money from the moment it is made, not from the moment it
 * is paid. Otherwise a seller with ₦50,000 could file five ₦50,000 requests
 * while an administrator was at lunch.
 */
class WithdrawalService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly PaymentGatewayManager $gateways,
        private readonly PayoutAccountService $accounts,
    ) {}

    /**
     * What is actually available to withdraw right now: the ledger balance
     * less anything already spoken for by a request in flight.
     */
    public function requestableBalance(User|int $user): int
    {
        return max(0, $this->wallet->availableBalance($user) - $this->reservedFor($user));
    }

    /**
     * Money held out of the balance by requests that have not yet produced a
     * ledger entry of their own.
     *
     * Once a withdrawal writes its ledger row the balance is already reduced by
     * it, so continuing to count it here would subtract the same money twice.
     */
    public function reservedFor(User|int $user, bool $lock = false): int
    {
        $query = Withdrawal::query()
            ->ownedBy($user)
            ->reserving()
            ->whereNull('wallet_transaction_id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) $query->sum('amount_kobo');
    }

    /**
     * The smallest payout the platform will send.
     */
    public function minimumKobo(): int
    {
        return max(0, (int) settings('minimum_withdrawal_amount', 500_000));
    }

    /**
     * Ask for a payout.
     *
     * Everything that decides whether this is allowed happens inside one
     * transaction with the ledger rows locked, so two requests racing each
     * other are serialised rather than both being told yes.
     */
    public function request(
        User $user,
        int $amountKobo,
        ?PayoutAccount $account = null,
        ?User $actor = null,
    ): Withdrawal {
        $account ??= $this->accounts->defaultFor($user);

        if ($account === null) {
            throw new RuntimeException(__('Add a bank account before asking for a payout.'));
        }

        if ($account->user_id !== $user->getKey()) {
            throw new RuntimeException(__('That account belongs to somebody else.'));
        }

        if (! $account->isPayable()) {
            throw new RuntimeException(__('That account has not been confirmed with the bank yet.'));
        }

        if ($amountKobo <= 0) {
            throw new RuntimeException(__('Enter how much you want to withdraw.'));
        }

        $minimum = $this->minimumKobo();

        if ($amountKobo < $minimum) {
            throw new RuntimeException(__('The smallest payout is :amount.', [
                'amount' => Money::fromKobo($minimum),
            ]));
        }

        return DB::transaction(function () use ($user, $amountKobo, $account, $actor): Withdrawal {
            /*
             * The locking read. This is the guard: a second request for the
             * same user waits here until the first has committed, and then
             * reads a balance that already accounts for it.
             */
            $available = $this->lockedAvailableBalance($user);

            if ($amountKobo > $available) {
                throw new RuntimeException(__('You only have :amount available.', [
                    'amount' => Money::fromKobo($available),
                ]));
            }

            $withdrawal = new Withdrawal;
            $withdrawal->forceFill([
                'user_id' => $user->getKey(),
                'payout_account_id' => $account->getKey(),
                'amount_kobo' => $amountKobo,
                'currency' => 'NGN',
                'status' => WithdrawalStatus::Requested,
                'requested_by' => ($actor ?? $user)->getKey(),
            ])->save();

            return $withdrawal;
        });
    }

    /**
     * An administrator agrees to a payout.
     */
    public function approve(Withdrawal $withdrawal, User $admin, ?string $note = null): Withdrawal
    {
        if ($withdrawal->status !== WithdrawalStatus::Requested) {
            throw new RuntimeException(__('This payout is no longer waiting for approval.'));
        }

        $withdrawal->forceFill([
            'status' => WithdrawalStatus::Approved,
            'approved_by' => $admin->getKey(),
            'approved_at' => now(),
            'admin_note' => $note,
        ])->save();

        return $withdrawal;
    }

    /**
     * An administrator says no. The money goes straight back into the balance,
     * because it was only ever reserved.
     */
    public function reject(Withdrawal $withdrawal, User $admin, string $reason): Withdrawal
    {
        if ($withdrawal->status->isFinished()) {
            throw new RuntimeException(__('This payout has already been settled.'));
        }

        if ($withdrawal->wallet_transaction_id !== null) {
            throw new RuntimeException(__('This payout has already been sent and cannot be turned down.'));
        }

        $withdrawal->forceFill([
            'status' => WithdrawalStatus::Rejected,
            'approved_by' => $admin->getKey(),
            'admin_note' => $reason,
            'processed_at' => now(),
        ])->save();

        return $withdrawal;
    }

    /**
     * Hand the transfer to the gateway and write the ledger entry.
     *
     * The entry is written when the gateway accepts, not when the money lands:
     * at that point it has genuinely left, and a balance that still showed it
     * would let the seller spend it again. If the transfer later fails,
     * `markFailed()` writes a reversal beside it rather than deleting it.
     */
    public function process(Withdrawal $withdrawal, ?User $actor = null): Withdrawal
    {
        if (! in_array($withdrawal->status, [WithdrawalStatus::Requested, WithdrawalStatus::Approved], true)) {
            throw new RuntimeException(__('This payout is not ready to be sent.'));
        }

        if ($withdrawal->wallet_transaction_id !== null) {
            throw new RuntimeException(__('This payout has already been sent.'));
        }

        $withdrawal->loadMissing(['payoutAccount', 'user']);
        $account = $withdrawal->payoutAccount;

        if ($account === null || ! $account->isPayable()) {
            throw new RuntimeException(__('That bank account cannot be paid.'));
        }

        $gateway = $this->gateways->gateway((string) settings('active_payment_gateway', 'paystack'));

        try {
            $result = $gateway->transfer(new TransferRequest(
                amountKobo: $withdrawal->amount_kobo,
                currency: $withdrawal->currency,
                accountNumber: (string) $account->account_number,
                bankCode: (string) $account->bank_code,
                accountName: (string) $account->account_name,
                reference: $withdrawal->reference,
                narration: __('Marketplace payout'),
            ));
        } catch (Throwable $exception) {
            report($exception);

            return $this->markFailed($withdrawal, __('We could not reach the payment provider. Nothing has left your balance.'));
        }

        if (! $result->accepted) {
            return $this->markFailed($withdrawal, $result->message ?? __('The payment provider would not send this.'));
        }

        return DB::transaction(function () use ($withdrawal, $result, $gateway, $actor, $account): Withdrawal {
            $entry = $this->wallet->record(
                user: $withdrawal->user_id,
                type: LedgerType::Withdrawal,
                // Negative, and in a state that still counts: a withdrawal has
                // to keep reducing the balance after it has been paid.
                amountKobo: -$withdrawal->amount_kobo,
                state: LedgerState::Withdrawn,
                description: __('Payout :reference to :bank', [
                    'reference' => $withdrawal->reference,
                    'bank' => $account->bank_name,
                ]),
                meta: [
                    'withdrawal_id' => $withdrawal->getKey(),
                    'gateway' => $gateway->key(),
                    'gateway_reference' => $result->reference,
                ],
                createdBy: $actor?->getKey(),
            );

            $withdrawal->forceFill([
                'status' => WithdrawalStatus::Processing,
                'gateway' => $gateway->key(),
                'gateway_reference' => $result->reference,
                'wallet_transaction_id' => $entry->getKey(),
                'processed_at' => now(),
            ])->save();

            return $withdrawal;
        });
    }

    /**
     * The gateway says the money landed.
     */
    public function markPaid(Withdrawal $withdrawal, ?string $gatewayReference = null): Withdrawal
    {
        if ($withdrawal->status === WithdrawalStatus::Paid) {
            return $withdrawal;
        }

        $withdrawal->forceFill([
            'status' => WithdrawalStatus::Paid,
            'gateway_reference' => $gatewayReference ?? $withdrawal->gateway_reference,
            'processed_at' => $withdrawal->processed_at ?? now(),
        ])->save();

        return $withdrawal;
    }

    /**
     * The transfer did not work.
     *
     * If a ledger entry had already been written, a reversal is placed beside
     * it. The original stays: it is the record that the money was sent, and a
     * ledger that can be rewritten is not evidence of anything.
     */
    public function markFailed(Withdrawal $withdrawal, string $reason): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $reason): Withdrawal {
            if ($withdrawal->wallet_transaction_id !== null) {
                $original = WalletTransaction::query()->find($withdrawal->wallet_transaction_id);

                if ($original !== null) {
                    $this->wallet->record(
                        user: $withdrawal->user_id,
                        type: LedgerType::Reversal,
                        amountKobo: -$original->amount_kobo,
                        state: LedgerState::Released,
                        description: __('Payout :reference failed', ['reference' => $withdrawal->reference]),
                        meta: [
                            'withdrawal_id' => $withdrawal->getKey(),
                            'reverses_transaction_id' => $original->getKey(),
                        ],
                    );
                }
            }

            $withdrawal->forceFill([
                'status' => WithdrawalStatus::Failed,
                'failure_reason' => $reason,
                'processed_at' => now(),
            ])->save();

            return $withdrawal;
        });
    }

    /**
     * The available balance, read under a row lock.
     *
     * Both halves of the sum are locked: the ledger rows the balance is made
     * of, and the withdrawals already reserving against it. Locking only one
     * would leave the other free to change underneath a concurrent request.
     */
    private function lockedAvailableBalance(User $user): int
    {
        $balance = (int) WalletTransaction::query()
            ->where('user_id', $user->getKey())
            ->whereIn('state', LedgerState::spendable())
            ->lockForUpdate()
            ->sum('amount_kobo');

        return max(0, $balance - $this->reservedFor($user, lock: true));
    }
}
