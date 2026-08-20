<?php

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\EngagementStatus;
use App\Enums\LedgerState;
use App\Enums\RoleName;
use App\Models\MentorProfile;
use App\Models\MentorshipEngagement;
use App\Models\MentorshipPackage;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Disputes\DisputeService;
use App\Services\Mentorship\EngagementService;
use App\Services\Mentorship\MentorshipCheckout;
use App\Services\Payments\PaymentProcessor;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SpecialisationSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Mentorship disputes.
 *
 * The same conservation law as the marketplace, restated for two different
 * people: whatever the mentor keeps, plus whatever the platform keeps, plus
 * whatever goes back to the client, equals what the client paid. Every
 * resolution path is checked against it, because a path that quietly leaves a
 * few naira unaccounted for is not a rounding problem, it is a hole.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(SpecialisationSeeder::class);

    app(SettingsService::class)->set('mentorship_commission_percent', '15', 'float', 'platform');

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    $this->disputes = app(DisputeService::class);
    $this->engagements = app(EngagementService::class);
    $this->wallet = app(WalletService::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);

    $this->client = User::factory()->create();
    $this->mentor = MentorProfile::factory()->approved()->create();

    $this->package = MentorshipPackage::factory()->pricedAt(2_000_000)
        ->create(['mentor_profile_id' => $this->mentor->id]);

    $this->hireAndPay = function (): MentorshipEngagement {
        $engagement = $this->engagements->request($this->client, $this->package);
        $invoice = $engagement->nextInvoice();
        $order = app(MentorshipCheckout::class)->begin($this->client, $invoice);

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 1,
                    'reference' => $order->reference,
                    'status' => 'success',
                    'amount' => $invoice->amount_kobo,
                    'currency' => 'NGN',
                    'paid_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);

        return $engagement->fresh();
    };

    /**
     * Mentor keeps + platform keeps + client gets back = client paid.
     */
    $this->conserves = function (MentorshipEngagement $engagement, int $paidKobo): void {
        $rows = WalletTransaction::query()
            ->whereIn('mentorship_invoice_id', $engagement->invoices()->pluck('id'))
            ->get();

        $counting = [LedgerState::Held, LedgerState::Released, LedgerState::Withdrawn];

        $mentorKeeps = $rows
            ->where('user_id', $engagement->mentor->user_id)
            ->whereIn('state', $counting)
            ->sum('amount_kobo');

        $platformKeeps = $rows
            ->whereNull('user_id')
            ->whereIn('state', $counting)
            ->sum('amount_kobo');

        $clientGetsBack = $rows
            ->where('user_id', $engagement->client_id)
            ->where('state', LedgerState::Refunded)
            ->sum('amount_kobo');

        expect($mentorKeeps + $platformKeeps + $clientGetsBack)->toBe($paidKobo);
    };
});

it('lets either side raise a dispute, unlike the marketplace', function () {
    $engagement = ($this->hireAndPay)();

    expect($this->disputes->canRaiseOnEngagement($engagement, $this->client))->toBeTrue()
        // A mentor whose client vanished after taking three months of advice
        // has a complaint worth hearing, and no other way to make it.
        ->and($this->disputes->canRaiseOnEngagement($engagement, $this->mentor->user))->toBeTrue()
        ->and($this->disputes->canRaiseOnEngagement($engagement, User::factory()->create()))->toBeFalse();
});

it('will not let anybody dispute an engagement nobody has paid for', function () {
    $engagement = $this->engagements->request($this->client, $this->package);

    expect($this->disputes->canRaiseOnEngagement($engagement, $this->client))->toBeFalse();
});

it('freezes the engagement so auto-confirmation cannot pay the mentor mid-argument', function () {
    $engagement = ($this->hireAndPay)();

    $this->engagements->markComplete($engagement, $this->mentor->user);

    $this->disputes->raiseOnEngagement(
        $engagement->fresh(),
        $this->client,
        DisputeReason::NotAsAgreed,
        'He came once and never came back.',
    );

    $engagement->refresh();

    expect($engagement->status)->toBe(EngagementStatus::Disputed)
        ->and($engagement->auto_confirm_at)->toBeNull();

    // The clock has run well past the window, and still nothing moves.
    $this->travel(30)->days();

    expect($this->engagements->autoConfirmDue())->toBe(0)
        ->and($this->wallet->availableBalance($this->mentor->user))->toBe(0)
        ->and($this->wallet->heldBalance($this->mentor->user))->toBe(1_700_000);
});

it('allows only one live dispute per engagement', function () {
    $engagement = ($this->hireAndPay)();

    $this->disputes->raiseOnEngagement($engagement, $this->client, DisputeReason::NotAsAgreed, 'First complaint');

    expect(fn () => $this->disputes->raiseOnEngagement(
        $engagement->fresh(),
        $this->mentor->user,
        DisputeReason::Other,
        'Second complaint',
    ))->toThrow(RuntimeException::class);
});

it('balances a decision for the mentor', function () {
    $engagement = ($this->hireAndPay)();

    $dispute = $this->disputes->raiseOnEngagement(
        $engagement,
        $this->client,
        DisputeReason::NotAsAgreed,
        'I do not think the advice was any good.',
    );

    $this->disputes->resolveEngagementForMentor($dispute, $this->admin, 'The report was delivered and is on file.');

    expect($this->wallet->availableBalance($this->mentor->user))->toBe(1_700_000)
        ->and($this->wallet->platformEarnings())->toBe(300_000)
        ->and($dispute->fresh()->status)->toBe(DisputeStatus::ResolvedSeller);

    ($this->conserves)($engagement->fresh(), 2_000_000);
});

it('balances a full refund to the client', function () {
    $engagement = ($this->hireAndPay)();

    $dispute = $this->disputes->raiseOnEngagement(
        $engagement,
        $this->client,
        DisputeReason::NotDelivered,
        'He never made contact at all.',
    );

    $this->disputes->resolveEngagementForClient($dispute, $this->admin, 'No evidence of any contact.');

    expect($this->wallet->availableBalance($this->mentor->user))->toBe(0)
        ->and($this->wallet->heldBalance($this->mentor->user))->toBe(0)
        // The platform gives up its commission with the rest of it.
        ->and($this->wallet->platformEarnings())->toBe(0)
        ->and($engagement->fresh()->status)->toBe(EngagementStatus::Refunded);

    ($this->conserves)($engagement->fresh(), 2_000_000);
});

it('balances a partial refund, with each side giving up its own share', function () {
    $engagement = ($this->hireAndPay)();

    $dispute = $this->disputes->raiseOnEngagement(
        $engagement,
        $this->client,
        DisputeReason::SessionsNotHeld,
        'Two of the four sessions happened.',
    );

    $this->disputes->resolveEngagementPartially($dispute, $this->admin, 1_000_000, 'Half the sessions were held.');

    // 15% of the ₦10,000 returned comes off the platform, the rest off the
    // mentor: neither pays for the other's half of a compromise.
    expect($this->wallet->availableBalance($this->mentor->user))->toBe(1_700_000 - 850_000)
        ->and($this->wallet->platformEarnings())->toBe(300_000 - 150_000)
        ->and($dispute->fresh()->refund_amount_kobo)->toBe(1_000_000);

    ($this->conserves)($engagement->fresh(), 2_000_000);
});

it('balances over a spread of partial refunds', function () {
    /*
     * One fake for the whole loop, reading the reference out of the URL and
     * numbering each answer. Registering a fresh Http::fake per iteration would
     * not work: Laravel MERGES stub callbacks rather than replacing them, so
     * the first one keeps matching and every order would come back with the
     * same gateway reference.
     */
    $call = 0;

    Http::fake(function ($request) use (&$call) {
        $reference = basename(parse_url($request->url(), PHP_URL_PATH) ?: '');

        return Http::response([
            'status' => true,
            'data' => [
                'id' => 9000 + (++$call),
                'reference' => $reference,
                'status' => 'success',
                'amount' => 2_000_000,
                'currency' => 'NGN',
                'paid_at' => now()->toIso8601String(),
            ],
        ]);
    });

    // A spread across the whole range, so a rounding hole anywhere in it shows.
    foreach ([1, 999, 100_000, 333_333, 666_667, 1_999_999] as $refund) {
        $client = User::factory()->create();
        $engagement = $this->engagements->request($client, $this->package);
        $order = app(MentorshipCheckout::class)->begin($client, $engagement->nextInvoice());

        app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);

        $dispute = $this->disputes->raiseOnEngagement(
            $engagement->fresh(),
            $client,
            DisputeReason::SessionsNotHeld,
            'Partly done.',
        );

        $this->disputes->resolveEngagementPartially($dispute, $this->admin, $refund, 'Split.');

        ($this->conserves)($engagement->fresh(), 2_000_000);
    }
});

it('keeps a mentorship dispute private to the two parties and an administrator', function () {
    $engagement = ($this->hireAndPay)();

    $dispute = $this->disputes->raiseOnEngagement(
        $engagement,
        $this->client,
        DisputeReason::Other,
        'Something went wrong.',
    );

    $this->actingAs($this->client)->get(route('disputes.show', $dispute))->assertOk();
    $this->actingAs($this->mentor->user)->get(route('disputes.show', $dispute))->assertOk();
    $this->actingAs($this->admin)->get(route('disputes.show', $dispute))->assertOk();

    $this->actingAs(User::factory()->create())
        ->get(route('disputes.show', $dispute))
        ->assertForbidden();
});

it('shows a mentorship dispute in the same list as a marketplace one', function () {
    $engagement = ($this->hireAndPay)();

    $this->disputes->raiseOnEngagement($engagement, $this->client, DisputeReason::Other, 'Something went wrong.');

    $this->actingAs($this->client)
        ->get(route('disputes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('disputes', 1)
            ->where('disputes.0.kind', 'mentorship')
            ->where('disputes.0.reference', $engagement->reference));
});
