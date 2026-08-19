<?php

namespace App\Services\Payouts;

use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Payments\Data\Bank;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Adding and verifying the bank accounts money is sent to.
 *
 * Nothing is saved until the bank has confirmed whose account it is. A record
 * that says `is_verified` because somebody ticked a box would be worse than no
 * record at all — it would look like a check had happened.
 */
class PayoutAccountService
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * The banks the active gateway can pay.
     *
     * @return array<int, Bank>
     */
    public function banks(): array
    {
        return $this->gateway()->banks();
    }

    /**
     * @return array<string, string> code => name
     */
    public function bankOptions(): array
    {
        return collect($this->banks())
            ->mapWithKeys(fn (Bank $bank): array => [$bank->code => $bank->name])
            ->all();
    }

    /**
     * Add an account, having asked the bank who owns it.
     *
     * The name that comes back is the one stored. If the bank will not confirm
     * the number, nothing is written: an unverified row would only invite
     * somebody to pay it later.
     */
    public function add(User $user, string $bankCode, string $accountNumber): PayoutAccount
    {
        $accountNumber = preg_replace('/\D/', '', $accountNumber) ?? '';

        if ($accountNumber === '') {
            throw new RuntimeException(__('Please enter your account number.'));
        }

        $existing = PayoutAccount::query()
            ->withTrashed()
            ->ownedBy($user)
            ->where('bank_code', $bankCode)
            ->where('account_number', $accountNumber)
            ->first();

        if ($existing !== null && $existing->trashed()) {
            $existing->restore();
        } elseif ($existing !== null) {
            throw new RuntimeException(__('You have already added that account.'));
        }

        $gateway = $this->gateway();
        $resolution = $gateway->resolveAccount($accountNumber, $bankCode);

        if (! $resolution->resolved || blank($resolution->accountName)) {
            throw new RuntimeException(
                $resolution->message ?? __('The bank did not recognise that account number.')
            );
        }

        $account = $existing ?? new PayoutAccount;

        $account->forceFill([
            'user_id' => $user->getKey(),
            'bank_code' => $bankCode,
            'bank_name' => $this->bankName($bankCode),
            'account_number' => $accountNumber,
            // From the bank, never from the form.
            'account_name' => $resolution->accountName,
            'is_verified' => true,
            'verified_at' => now(),
            'gateway' => $gateway->key(),
        ])->save();

        // The first account somebody adds is the one they get paid into,
        // because being asked to choose a default out of one is silly.
        if (PayoutAccount::query()->ownedBy($user)->where('is_default', true)->doesntExist()) {
            $account->makeDefault();
        }

        return $account->refresh();
    }

    /**
     * Where this user's money goes, or null if they have not said.
     */
    public function defaultFor(User $user): ?PayoutAccount
    {
        return PayoutAccount::query()
            ->ownedBy($user)
            ->verified()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return Collection<int, PayoutAccount>
     */
    public function accountsFor(User $user): Collection
    {
        return PayoutAccount::query()
            ->ownedBy($user)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * Remove an account, unless money is on its way to it.
     */
    public function remove(PayoutAccount $account): void
    {
        if ($account->withdrawals()->reserving()->exists()) {
            throw new RuntimeException(
                __('There is a payout on its way to this account. It cannot be removed yet.')
            );
        }

        $wasDefault = $account->is_default;
        $account->delete();

        if (! $wasDefault) {
            return;
        }

        // Somebody must still have a default, or the next payout has nowhere
        // to go.
        PayoutAccount::query()
            ->ownedBy($account->user_id)
            ->verified()
            ->orderBy('id')
            ->first()
            ?->makeDefault();
    }

    private function bankName(string $code): string
    {
        return $this->bankOptions()[$code] ?? $code;
    }

    private function gateway()
    {
        return $this->gateways->gateway((string) settings('active_payment_gateway', 'paystack'));
    }
}
