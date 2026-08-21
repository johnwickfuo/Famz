<?php

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Models\Order;
use App\Models\PaymentWebhook;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\ReconciliationAlert;
use App\Services\Payments\WebhookHandler;
use App\Services\Reporting\ReconciliationReport;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The safety net, tested by breaking things on purpose.
 *
 * A reconciliation report that passes on clean books proves almost nothing —
 * so does a report that does nothing at all. Every test here damages the ledger
 * in a specific way and asserts the report notices, which is the only property
 * that matters.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->report = app(ReconciliationReport::class);

    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);
    $this->sellerUser = $sellerUser;
    $this->seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $this->findingsFor = fn (array $result, string $check): array => collect($result['findings'])
        ->where('check', $check)
        ->values()
        ->all();
});

it('reports clean books as clean', function (): void {
    $result = $this->report->run();

    expect($result['clean'])->toBeTrue()
        ->and($result['findings'])->toBe([])
        ->and($result['critical'])->toBe(0);
});

it('runs all four checks', function (): void {
    expect(collect($this->report->run()['checks'])->pluck('key')->all())
        ->toBe(['wallet_sum', 'negative_balance', 'order_ledger', 'gateway']);
});

it('notices a negative balance', function (): void {
    /*
     * There is no legitimate path to this: a withdrawal is checked against
     * available funds under a row lock. Reaching one means that guard failed
     * and the money has already gone.
     */
    WalletTransaction::query()->create([
        'user_id' => $this->sellerUser->id,
        'type' => LedgerType::Withdrawal,
        'amount_kobo' => -500_000,
        'state' => LedgerState::Released,
        'description' => 'a withdrawal with nothing behind it',
    ]);

    $findings = ($this->findingsFor)($this->report->run(), 'negative_balance');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['severity'])->toBe(ReconciliationReport::SEVERITY_CRITICAL)
        ->and($findings[0]['message'])->toContain('negative balance');
});

it('notices a paid order whose seller was never credited', function (): void {
    /*
     * The most consequential finding on the report. A buyer paid, the platform
     * holds the money, and no seller has been credited for it — they will find
     * out before we do, and they will be right to be angry.
     */
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ]);

    $subOrder = SubOrder::factory()->create([
        'order_id' => $order->id,
        'seller_id' => $this->seller->id,
    ]);

    $findings = ($this->findingsFor)($this->report->run(), 'order_ledger');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['subject'])->toBe($subOrder->reference)
        ->and($findings[0]['severity'])->toBe(ReconciliationReport::SEVERITY_CRITICAL);
});

it('accepts a paid order that does have its entries', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ]);

    $subOrder = SubOrder::factory()->create([
        'order_id' => $order->id,
        'seller_id' => $this->seller->id,
    ]);

    WalletTransaction::query()->create([
        'user_id' => $this->sellerUser->id,
        'sub_order_id' => $subOrder->id,
        'type' => LedgerType::Sale,
        'amount_kobo' => 900_000,
        'state' => LedgerState::Released,
        'description' => 'sale',
    ]);

    expect(($this->findingsFor)($this->report->run(), 'order_ledger'))->toBe([]);
});

it('notices a gateway confirmation the platform never matched to an order', function (): void {
    /*
     * The only check that reaches outside the platform. A webhook that arrived
     * while the application was down looks like nothing at all from the inside
     * — no order, no error, no trace — which is exactly why it needs a check of
     * its own.
     */
    PaymentWebhook::query()->create([
        'gateway' => 'paystack',
        'event_id' => 'evt_'.uniqid(),
        'event_type' => 'charge.success',
        'gateway_reference' => 'PS-UNMATCHED-001',
        'signature_valid' => true,
        'payload' => [],
        'outcome' => WebhookHandler::OUTCOME_UNMATCHED,
        'processed_at' => now(),
    ]);

    $findings = ($this->findingsFor)($this->report->run(), 'gateway');

    expect($findings)->not->toBeEmpty()
        ->and($findings[0]['subject'])->toBe('PS-UNMATCHED-001')
        ->and($findings[0]['severity'])->toBe(ReconciliationReport::SEVERITY_CRITICAL);
});

it('notices webhooks that failed their signature check', function (): void {
    PaymentWebhook::query()->create([
        'gateway' => 'paystack',
        'event_id' => 'evt_'.uniqid(),
        'event_type' => 'charge.success',
        'signature_valid' => false,
        'payload' => [],
        'outcome' => WebhookHandler::OUTCOME_INVALID_SIGNATURE,
        'processed_at' => now(),
    ]);

    $findings = ($this->findingsFor)($this->report->run(), 'gateway');

    // A warning, not critical: the usual cause is a key rotated on one side
    // only, which is a configuration problem rather than missing money.
    expect(collect($findings)->pluck('severity'))
        ->toContain(ReconciliationReport::SEVERITY_WARNING);
});

it('exits non-zero when something critical is wrong', function (): void {
    WalletTransaction::query()->create([
        'user_id' => $this->sellerUser->id,
        'type' => LedgerType::Withdrawal,
        'amount_kobo' => -100,
        'state' => LedgerState::Released,
        'description' => 'broken',
    ]);

    // The exit code is the contract an external monitor watches, so it does
    // not have to parse anything.
    $this->artisan('ledger:reconcile')->assertExitCode(1);
});

it('exits zero on clean books', function (): void {
    $this->artisan('ledger:reconcile')
        ->expectsOutputToContain('Everything agrees.')
        ->assertExitCode(0);
});

it('alerts every administrator when asked to', function (): void {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(RoleName::Admin->value);

    WalletTransaction::query()->create([
        'user_id' => $this->sellerUser->id,
        'type' => LedgerType::Withdrawal,
        'amount_kobo' => -100,
        'state' => LedgerState::Released,
        'description' => 'broken',
    ]);

    $this->artisan('ledger:reconcile --alert');

    Notification::assertSentTo($admin, ReconciliationAlert::class);
});

it('stays quiet when the books agree', function (): void {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->assignRole(RoleName::Admin->value);

    $this->artisan('ledger:reconcile --alert')->assertExitCode(0);

    /*
     * A nightly job that reports success three hundred times a year is a
     * nightly job whose emails get filtered — and the one that matters goes to
     * the same folder.
     */
    Notification::assertNothingSent();
});

it('says so rather than failing silently when there is nobody to alert', function (): void {
    WalletTransaction::query()->create([
        'user_id' => $this->sellerUser->id,
        'type' => LedgerType::Withdrawal,
        'amount_kobo' => -100,
        'state' => LedgerState::Released,
        'description' => 'broken',
    ]);

    // A safety net with nobody on the other end is not a safety net, and that
    // should be visible rather than inferred from silence.
    $this->artisan('ledger:reconcile --alert')
        ->expectsOutputToContain('No administrators to alert.');
});

it('narrows to a date range when asked', function (): void {
    $old = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subYear(),
    ]);

    SubOrder::factory()->create(['order_id' => $old->id, 'seller_id' => $this->seller->id]);

    // The broken order is a year old, so a report about this month should not
    // mention it.
    $findings = ($this->findingsFor)(
        $this->report->run(now()->startOfMonth(), now()),
        'order_ledger',
    );

    expect($findings)->toBe([]);
});

it('counts every revenue stream in the platform total', function (): void {
    /*
     * Not just commission. Four of the platform's revenue streams — courses,
     * consultations, study fees and the mentorship cut — land on the platform
     * account under their own ledger types, and a total that counted only
     * Commission reported the marketplace's takings as the whole business.
     *
     * The reconciliation is what caught it: check one compares the reported
     * figure against the raw sum of the platform's own entries, and they had
     * drifted apart by exactly the non-marketplace revenue.
     */
    // A list of pairs rather than a keyed array: an enum case cannot be an
    // array key.
    $streams = [
        [LedgerType::Commission, 10_000_00],
        [LedgerType::CourseSale, 15_000_00],
        [LedgerType::ConsultationFee, 50_000_00],
        [LedgerType::QuotationStudyFee, 20_000_00],
    ];

    foreach ($streams as [$type, $amount]) {
        WalletTransaction::query()->create([
            'user_id' => null,
            'type' => $type,
            'amount_kobo' => $amount,
            'state' => LedgerState::Released,
            'description' => 'demo revenue',
        ]);
    }

    expect(app(WalletService::class)->platformEarnings())
        ->toBe(collect($streams)->sum(fn (array $pair): int => $pair[1]));

    // And the books still agree, which is the property that broke first.
    expect($this->report->run()['clean'])->toBeTrue();
});

it('does not report a difference for money that never had a seller', function (): void {
    /*
     * A course, a consultation and a study fee each produce a paid order with
     * no sub-orders at all — the money is the platform's and there is nobody to
     * split with. The totals line counted those orders as collected and their
     * ledger entries as nothing, so a platform selling anything besides
     * marketplace goods showed a permanent phantom difference on the one line
     * that is meant to be the headline reassurance.
     */
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
        'grand_total_kobo' => 50_000_00,
    ]);

    WalletTransaction::query()->create([
        'user_id' => null,
        'type' => LedgerType::ConsultationFee,
        'amount_kobo' => 50_000_00,
        'state' => LedgerState::Released,
        'description' => 'Consultation for order '.$order->reference,
    ]);

    $totals = $this->report->run()['totals'];

    expect($totals['difference_kobo'])->toBe(0);
});
