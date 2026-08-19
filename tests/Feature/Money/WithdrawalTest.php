<?php

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\PayoutMode;
use App\Enums\WithdrawalStatus;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Payouts\PayoutAccountService;
use App\Services\Payouts\ScheduledPayoutSweep;
use App\Services\Payouts\WithdrawalService;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Money leaving the platform.
 *
 * The whole point of these is that a balance is a sum, not a stored number, so
 * two requests arriving together would both read the same figure unless
 * something stops them. Most of what follows is about that.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config()->set('services.paystack.secret_key', 'sk_test_paystack');

    $this->settings = app(SettingsService::class);
    $this->wallet = app(WalletService::class);
    $this->withdrawals = app(WithdrawalService::class);

    $this->seller = User::factory()->create();
    $this->account = PayoutAccount::factory()->for($this->seller)->create();

    // ₦50,000 earned and available.
    $this->credit = function (int $kobo, LedgerState $state = LedgerState::Released): WalletTransaction {
        return $this->wallet->record($this->seller, LedgerType::Sale, $kobo, $state, 'Sale');
    };

    ($this->credit)(5_000_000);

    $this->fakeTransfer = function (bool $ok = true): void {
        Http::fake([
            '*/transferrecipient' => Http::response([
                'status' => true,
                'data' => ['recipient_code' => 'RCP_test'],
            ]),
            '*/transfer' => Http::response([
                'status' => $ok,
                'message' => $ok ? 'Transfer queued' : 'Insufficient balance on the platform account',
                'data' => ['reference' => 'TRF_test', 'status' => 'pending'],
            ], $ok ? 200 : 400),
        ]);
    };
});

// ---------------------------------------------------------------------------
// The limit
// ---------------------------------------------------------------------------

it('will not let a seller withdraw more than they have', function () {
    expect(fn () => $this->withdrawals->request($this->seller, 5_000_001))
        ->toThrow(RuntimeException::class);

    expect(Withdrawal::query()->count())->toBe(0);
});

it('will not count held money as available', function () {
    ($this->credit)(9_000_000, LedgerState::Held);

    expect($this->wallet->heldBalance($this->seller))->toBe(9_000_000);

    // The escrow money is not theirs yet, whatever the total looks like.
    expect(fn () => $this->withdrawals->request($this->seller, 6_000_000))
        ->toThrow(RuntimeException::class);

    $this->withdrawals->request($this->seller, 5_000_000);

    expect(Withdrawal::query()->sole()->amount_kobo)->toBe(5_000_000);
});

it('enforces the minimum payout', function () {
    $this->settings->set('minimum_withdrawal_amount', '1000000', 'int', 'platform');

    expect(fn () => $this->withdrawals->request($this->seller, 900_000))
        ->toThrow(RuntimeException::class);

    $this->withdrawals->request($this->seller, 1_000_000);

    expect(Withdrawal::query()->count())->toBe(1);
});

it('reserves the money from the moment it is asked for', function () {
    $this->withdrawals->request($this->seller, 3_000_000);

    // The ledger has not moved — the sale is still there in full.
    expect($this->wallet->availableBalance($this->seller))->toBe(5_000_000)
        // But only ₦20,000 of it can be asked for again.
        ->and($this->withdrawals->requestableBalance($this->seller))->toBe(2_000_000);

    expect(fn () => $this->withdrawals->request($this->seller, 3_000_000))
        ->toThrow(RuntimeException::class);
});

it('gives the money back when a request is turned down', function () {
    $admin = User::factory()->create();
    $withdrawal = $this->withdrawals->request($this->seller, 5_000_000);

    expect($this->withdrawals->requestableBalance($this->seller))->toBe(0);

    $this->withdrawals->reject($withdrawal, $admin, 'The account name does not match the business.');

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Rejected)
        ->and($this->withdrawals->requestableBalance($this->seller))->toBe(5_000_000)
        // Nothing was ever written to the ledger, so there is nothing to undo.
        ->and(WalletTransaction::query()->where('type', LedgerType::Withdrawal)->count())->toBe(0);
});

it('refuses an account that has not been confirmed with the bank', function () {
    $unverified = PayoutAccount::factory()->for($this->seller)->unverified()->create([
        'account_number' => '9999999999',
        'is_default' => false,
    ]);

    expect(fn () => $this->withdrawals->request($this->seller, 1_000_000, $unverified))
        ->toThrow(RuntimeException::class);
});

it('refuses somebody else\'s bank account', function () {
    $theirs = PayoutAccount::factory()->create();

    expect(fn () => $this->withdrawals->request($this->seller, 1_000_000, $theirs))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// Paying it out
// ---------------------------------------------------------------------------

it('writes the ledger entry when the gateway takes the transfer', function () {
    ($this->fakeTransfer)();

    $withdrawal = $this->withdrawals->request($this->seller, 3_000_000);
    $this->withdrawals->process($withdrawal);

    $withdrawal->refresh();

    expect($withdrawal->status)->toBe(WithdrawalStatus::Processing)
        ->and($withdrawal->wallet_transaction_id)->not->toBeNull();

    $entry = $withdrawal->ledgerEntry;

    expect($entry->type)->toBe(LedgerType::Withdrawal)
        // Negative, and in a state that still counts: the balance has to stay
        // reduced after the money has gone.
        ->and($entry->amount_kobo)->toBe(-3_000_000)
        ->and($entry->state)->toBe(LedgerState::Withdrawn)
        ->and($this->wallet->availableBalance($this->seller))->toBe(2_000_000)
        // And it stops reserving, because the ledger now does that job.
        ->and($this->withdrawals->requestableBalance($this->seller))->toBe(2_000_000);
});

it('puts the money back with a reversal when a sent transfer fails', function () {
    ($this->fakeTransfer)();

    $withdrawal = $this->withdrawals->request($this->seller, 3_000_000);
    $this->withdrawals->process($withdrawal);

    expect($this->wallet->availableBalance($this->seller))->toBe(2_000_000);

    $this->withdrawals->markFailed($withdrawal->fresh(), 'The bank rejected the account.');

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Failed)
        ->and($this->wallet->availableBalance($this->seller))->toBe(5_000_000);

    // Corrected beside the original, never by rewriting it.
    expect(WalletTransaction::query()->where('type', LedgerType::Withdrawal)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', LedgerType::Reversal)->sole()->amount_kobo)
        ->toBe(3_000_000);
});

it('leaves the balance alone when the gateway refuses the transfer outright', function () {
    ($this->fakeTransfer)(ok: false);

    $withdrawal = $this->withdrawals->request($this->seller, 3_000_000);
    $this->withdrawals->process($withdrawal);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Failed)
        ->and($this->wallet->availableBalance($this->seller))->toBe(5_000_000)
        ->and($this->withdrawals->requestableBalance($this->seller))->toBe(5_000_000)
        // Nothing reached the ledger, so nothing needs reversing.
        ->and(WalletTransaction::query()->whereIn('type', [LedgerType::Withdrawal, LedgerType::Reversal])->count())
        ->toBe(0);
});

it('will not send the same payout twice', function () {
    ($this->fakeTransfer)();

    $withdrawal = $this->withdrawals->request($this->seller, 3_000_000);
    $this->withdrawals->process($withdrawal);

    expect(fn () => $this->withdrawals->process($withdrawal->fresh()))
        ->toThrow(RuntimeException::class);

    expect(WalletTransaction::query()->where('type', LedgerType::Withdrawal)->count())->toBe(1);
});

it('will not let an administrator turn down a payout that has already gone', function () {
    ($this->fakeTransfer)();

    $withdrawal = $this->withdrawals->request($this->seller, 3_000_000);
    $this->withdrawals->process($withdrawal);

    expect(fn () => $this->withdrawals->reject($withdrawal->fresh(), User::factory()->create(), 'Too late'))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// The scheduled sweep
// ---------------------------------------------------------------------------

it('sweeps every balance above the minimum on the payout day', function () {
    ($this->fakeTransfer)();

    $this->settings->set('payout_mode', PayoutMode::ScheduledAuto->value, 'string', 'platform');
    $this->settings->set('minimum_withdrawal_amount', '1000000', 'int', 'platform');

    // Somebody with too little to be worth a transfer fee.
    $small = User::factory()->create();
    PayoutAccount::factory()->for($small)->create(['account_number' => '1111111111']);
    $this->wallet->record($small, LedgerType::Sale, 400_000, LedgerState::Released, 'Small sale');

    // Somebody with money and nowhere to send it.
    $noAccount = User::factory()->create();
    $this->wallet->record($noAccount, LedgerType::Sale, 8_000_000, LedgerState::Released, 'Sale');

    $result = app(ScheduledPayoutSweep::class)->run();

    expect($result['swept'])->toBe(1)
        ->and($result['total_kobo'])->toBe(5_000_000)
        ->and($result['problems'])->toHaveCount(1)
        ->and($this->wallet->availableBalance($this->seller))->toBe(0)
        ->and($this->wallet->availableBalance($small))->toBe(400_000);
});

it('pays nobody twice when the sweep runs again the same day', function () {
    ($this->fakeTransfer)();

    $this->settings->set('payout_mode', PayoutMode::ScheduledAuto->value, 'string', 'platform');

    $sweep = app(ScheduledPayoutSweep::class);

    expect($sweep->run()['swept'])->toBe(1)
        ->and($sweep->run()['swept'])->toBe(0)
        ->and(Withdrawal::query()->count())->toBe(1);
});

it('lands on the last day of a short month when the payout day overruns it', function () {
    $this->settings->set('payout_schedule_day', '31', 'int', 'platform');

    $sweep = app(ScheduledPayoutSweep::class);

    // February 2027 has 28 days, so the 31st has to mean the 28th or nobody
    // gets paid that month.
    expect($sweep->isDueOn(CarbonImmutable::parse('2027-02-28')))->toBeTrue()
        ->and($sweep->isDueOn(CarbonImmutable::parse('2027-02-27')))->toBeFalse()
        ->and($sweep->isDueOn(CarbonImmutable::parse('2027-03-31')))->toBeTrue()
        ->and($sweep->isDueOn(CarbonImmutable::parse('2027-03-30')))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Payout accounts
// ---------------------------------------------------------------------------

it('stores the name the bank gives, not the one the seller types', function () {
    Http::fake(['*/bank/resolve*' => Http::response([
        'status' => true,
        'data' => ['account_name' => 'ADEBAYO FARMS LIMITED', 'account_number' => '0123456789'],
    ]), '*/bank*' => Http::response([
        'status' => true,
        'data' => [['code' => '058', 'name' => 'Guaranty Trust Bank']],
    ])]);

    $user = User::factory()->create();
    $account = app(PayoutAccountService::class)->add($user, '058', '0123456789');

    expect($account->account_name)->toBe('ADEBAYO FARMS LIMITED')
        ->and($account->bank_name)->toBe('Guaranty Trust Bank')
        ->and($account->is_verified)->toBeTrue()
        // The first account somebody adds is the one they get paid into.
        ->and($account->is_default)->toBeTrue();
});

it('saves nothing when the bank does not recognise the number', function () {
    Http::fake([
        '*/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name'], 422),
        '*/bank*' => Http::response(['status' => true, 'data' => []]),
    ]);

    $user = User::factory()->create();

    expect(fn () => app(PayoutAccountService::class)->add($user, '058', '0000000000'))
        ->toThrow(RuntimeException::class);

    // An unverified row would only invite somebody to pay it later.
    expect(PayoutAccount::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('keeps exactly one default account', function () {
    $second = PayoutAccount::factory()->for($this->seller)->create([
        'account_number' => '5555555555',
        'is_default' => false,
    ]);

    $second->makeDefault();

    expect(PayoutAccount::query()->where('user_id', $this->seller->id)->where('is_default', true)->count())
        ->toBe(1)
        ->and($this->account->fresh()->is_default)->toBeFalse()
        ->and(app(PayoutAccountService::class)->defaultFor($this->seller)->id)->toBe($second->id);
});

it('will not remove an account with a payout on its way to it', function () {
    $this->withdrawals->request($this->seller, 3_000_000);

    expect(fn () => app(PayoutAccountService::class)->remove($this->account))
        ->toThrow(RuntimeException::class);
});
