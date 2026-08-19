<?php

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\RoleName;
use App\Enums\SubOrderStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Admin\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Admin\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\PayoutAccount;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Disputes\DisputeService;
use App\Services\Orders\FulfilmentService;
use App\Services\Payouts\WithdrawalService;
use App\Services\Reporting\PlatformFinances;
use App\Services\Settings\SettingsService;
use App\Services\Settlement\EscrowDriver;
use App\Services\Settlement\SettlementManager;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

/**
 * The administrator's side of the money: the payout queue, the disputes queue,
 * the ledger explorer and the reconciliation figures.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config()->set('services.paystack.secret_key', 'sk_test_paystack');

    Filament::setCurrentPanel('admin');

    $this->settings = app(SettingsService::class);
    $this->wallet = app(WalletService::class);
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
    $this->actingAs($this->admin);

    $this->seller = User::factory()->create();
    $this->seller->assignRole(RoleName::Seller->value);
    PayoutAccount::factory()->for($this->seller)->create();

    $this->wallet->record($this->seller, LedgerType::Sale, 5_000_000, LedgerState::Released, 'Sale');

    Http::fake([
        '*/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP']]),
        '*/transfer' => Http::response([
            'status' => true,
            'data' => ['reference' => 'TRF_1', 'status' => 'pending'],
        ]),
    ]);
});

// ---------------------------------------------------------------------------
// The payout queue
// ---------------------------------------------------------------------------

it('lists payouts waiting on an administrator', function () {
    $mine = app(WithdrawalService::class)->request($this->seller, 3_000_000);
    $done = Withdrawal::factory()->create(['status' => WithdrawalStatus::Paid]);

    livewire(ListWithdrawals::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$done]);
});

it('approves and sends a payout in one step', function () {
    $withdrawal = app(WithdrawalService::class)->request($this->seller, 3_000_000);

    livewire(ListWithdrawals::class)
        ->callAction(TestAction::make('approveAndSend')->table($withdrawal));

    $withdrawal->refresh();

    expect($withdrawal->status)->toBe(WithdrawalStatus::Processing)
        ->and($withdrawal->approved_by)->toBe($this->admin->id)
        ->and($withdrawal->wallet_transaction_id)->not->toBeNull()
        ->and($this->wallet->availableBalance($this->seller))->toBe(2_000_000);
});

it('demands a reason before turning a payout down', function () {
    $withdrawal = app(WithdrawalService::class)->request($this->seller, 3_000_000);

    livewire(ListWithdrawals::class)
        ->callAction(TestAction::make('rejectWithdrawal')->table($withdrawal), ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Requested);
});

it('gives the money back when an administrator turns a payout down', function () {
    $withdrawal = app(WithdrawalService::class)->request($this->seller, 5_000_000);

    expect(app(WithdrawalService::class)->requestableBalance($this->seller))->toBe(0);

    livewire(ListWithdrawals::class)->callAction(
        TestAction::make('rejectWithdrawal')->table($withdrawal),
        ['reason' => 'The account name does not match the registered business.'],
    );

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Rejected)
        ->and(app(WithdrawalService::class)->requestableBalance($this->seller))->toBe(5_000_000);
});

// ---------------------------------------------------------------------------
// The disputes queue
// ---------------------------------------------------------------------------

it('arbitrates a dispute from the queue', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->paid()->create(['user_id' => $buyer->id]);
    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create([
        'order_id' => $order->id,
        'status' => SubOrderStatus::Delivered,
        'delivered_at' => now()->subDay(),
    ]);

    app(SettlementManager::class)->driver()->recordSale($subOrder);

    $dispute = app(DisputeService::class)->raise(
        $subOrder->fresh(),
        $buyer,
        DisputeReason::QualityPoor,
        'The maize was full of weevils.',
    );

    livewire(ListDisputes::class)
        ->assertCanSeeTableRecords([$dispute])
        ->callAction(TestAction::make('resolvePartially')->table($dispute), [
            'amount' => 5_000,
            'note' => 'Half the bag was usable, so half the money goes back.',
        ]);

    $dispute->refresh();

    expect($dispute->status)->toBe(DisputeStatus::ResolvedPartial)
        ->and($dispute->refund_amount_kobo)->toBe(500_000)
        ->and($dispute->resolved_by)->toBe($this->admin->id)
        ->and($this->wallet->availableBalance($subOrder->seller->user))->toBe(1_900_000 - 475_000);
});

it('keeps an internal note out of what the parties see', function () {
    $dispute = Dispute::factory()->create();

    app(DisputeService::class)->comment($dispute, $this->admin, 'Third complaint about this seller.', internal: true);

    $buyer = $dispute->subOrder->order->user;

    $this->actingAs($buyer)
        ->get(route('disputes.show', $dispute))
        ->assertInertia(fn ($page) => $page
            ->component('Disputes/Show')
            ->count('messages', 0));
});

// ---------------------------------------------------------------------------
// The ledger explorer
// ---------------------------------------------------------------------------

it('finds ledger entries by order reference', function () {
    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create();
    app(SettlementManager::class)->driver()->recordSale($subOrder);

    $unrelated = WalletTransaction::query()->where('sub_order_id', null)->get();

    livewire(ListLedgerEntries::class)
        ->filterTable('reference', ['reference' => $subOrder->reference])
        ->assertCanSeeTableRecords(WalletTransaction::query()->where('sub_order_id', $subOrder->id)->get())
        ->assertCanNotSeeTableRecords($unrelated);
});

it('shows the platform\'s own entries under no name at all', function () {
    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create();
    app(SettlementManager::class)->driver()->recordSale($subOrder);

    $commission = WalletTransaction::query()->where('type', LedgerType::Commission)->sole();

    expect($commission->user_id)->toBeNull();

    livewire(ListLedgerEntries::class)
        ->filterTable('platform', true)
        ->assertCanSeeTableRecords([$commission]);
});

// ---------------------------------------------------------------------------
// Reconciliation
// ---------------------------------------------------------------------------

it('balances what buyers were charged against what the ledger says', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->paid()->create([
        'user_id' => $buyer->id,
        'subtotal_kobo' => 2_000_000,
        'delivery_total_kobo' => 300_000,
        'grand_total_kobo' => 2_300_000,
    ]);

    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create([
        'order_id' => $order->id,
        'delivery_fee_kobo' => 300_000,
    ]);

    app(SettlementManager::class)->driver()->recordSale($subOrder);

    $figures = app(PlatformFinances::class)->reconciliation();

    expect($figures['collected_kobo'])->toBe(2_300_000)
        ->and($figures['credited_kobo'])->toBe(2_300_000)
        // Every naira charged is accounted for on the ledger.
        ->and($figures['difference_kobo'])->toBe(0);
});

it('still balances after a dispute is refunded in full', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->paid()->create([
        'user_id' => $buyer->id,
        'subtotal_kobo' => 2_000_000,
        'delivery_total_kobo' => 300_000,
        'grand_total_kobo' => 2_300_000,
    ]);

    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create([
        'order_id' => $order->id,
        'delivery_fee_kobo' => 300_000,
        'status' => SubOrderStatus::Delivered,
        'delivered_at' => now()->subDay(),
    ]);

    app(SettlementManager::class)->driver()->recordSale($subOrder);

    $dispute = app(DisputeService::class)->raise(
        $subOrder->fresh(),
        $buyer,
        DisputeReason::NotDelivered,
        'It never came, whatever the seller marked.',
    );

    app(DisputeService::class)->resolveForBuyer($dispute, $this->admin, 'No proof of delivery.');

    $figures = app(PlatformFinances::class)->reconciliation();

    expect($figures['refunded_kobo'])->toBe(2_300_000)
        // Nothing is owed to anybody and nothing was charged that is not
        // accounted for.
        ->and($figures['expected_kobo'])->toBe(0)
        ->and($figures['net_ledger_kobo'])->toBe(0)
        ->and($figures['difference_kobo'])->toBe(0)
        ->and($figures['owed_to_sellers_kobo'])->toBe(5_000_000);
});

it('reports commission month by month', function () {
    $old = SubOrder::factory()->pricedAt(2_000_000, 5)->create();
    app(SettlementManager::class)->driver()->recordSale($old);

    WalletTransaction::query()
        ->where('type', LedgerType::Commission)
        ->update(['created_at' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDay(), 'state' => LedgerState::Released]);

    $recent = SubOrder::factory()->pricedAt(4_000_000, 5)->create();
    app(SettlementManager::class)->driver()->recordSale($recent);
    WalletTransaction::query()
        ->where('type', LedgerType::Commission)
        ->where('sub_order_id', $recent->id)
        ->update(['state' => LedgerState::Released]);

    $byMonth = app(PlatformFinances::class)->commissionByMonth(6);

    expect($byMonth)->toHaveCount(6)
        ->and($byMonth->get(now()->subMonthsNoOverflow(2)->format('Y-m')))->toBe(100_000)
        ->and($byMonth->get(now()->format('Y-m')))->toBe(200_000);
});

it('lets nobody but an administrator near the money screens', function () {
    $this->actingAs($this->seller);

    $this->get('/admin/withdrawals')->assertForbidden();
    $this->get('/admin/disputes')->assertForbidden();
    $this->get('/admin/ledger-entries')->assertForbidden();
    $this->get('/admin/reconciliation')->assertForbidden();
});

it('does not count a cancelled escrow sale as still credited', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->paid()->create([
        'user_id' => $buyer->id,
        'subtotal_kobo' => 2_000_000,
        'delivery_total_kobo' => 300_000,
        'grand_total_kobo' => 2_300_000,
    ]);

    $subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create([
        'order_id' => $order->id,
        'delivery_fee_kobo' => 300_000,
    ]);

    app(SettlementManager::class)->driver()->recordSale($subOrder);

    // A rejection under escrow cancels the held entries in place rather than
    // writing reversals, so a reconciliation that summed every state would
    // report the money as still credited and the books as out by exactly the
    // refund.
    app(FulfilmentService::class)->reject($subOrder->fresh(), 'Out of stock.');

    $figures = app(PlatformFinances::class)->reconciliation();

    expect($figures['refunded_kobo'])->toBe(2_300_000)
        ->and($figures['net_ledger_kobo'])->toBe(0)
        ->and($figures['difference_kobo'])->toBe(0);
});

it('renders the dispute page an arbitrator actually works from', function () {
    $dispute = Dispute::factory()->create();

    // A smoke test, because the header on this page is assembled from closures
    // that only run when it is rendered — a broken one is invisible to every
    // other test in this file.
    $this->get("/admin/disputes/{$dispute->id}")
        ->assertOk()
        ->assertSee($dispute->subOrder->reference)
        ->assertSee('Settle this');
});

it('renders the reconciliation page', function () {
    $this->get('/admin/reconciliation')->assertOk();
});

it('renders the seller earnings dashboard', function () {
    $this->actingAs($this->seller)->get('/seller')->assertOk();
});
