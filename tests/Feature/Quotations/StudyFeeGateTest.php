<?php

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\RoleName;
use App\Enums\StudyFeeCreditStatus;
use App\Models\Order;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentProcessor;
use App\Services\Quotations\QuotationRequestService;
use App\Services\Quotations\QuotationService;
use App\Services\Quotations\StudyFeeCheckout;
use App\Services\Quotations\StudyFeeService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * The gate.
 *
 * Preparing a farm proposal is days of costing work against one particular
 * site. Doing that for everybody who fills in a form is how the service stops
 * being offered, so the study fee is a filter as much as it is revenue — and
 * the tests here are all about the fee being the thing that turns an enquiry
 * into work, not a formality on the way.
 */
beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
    Storage::fake('public');

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    $this->requests = app(QuotationRequestService::class);
    $this->fees = app(StudyFeeService::class);
    $this->checkout = app(StudyFeeCheckout::class);
    $this->quotations = app(QuotationService::class);

    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->admin->assignRole(RoleName::Admin->value);

    $this->client = User::factory()->create(['email' => 'client@example.test']);

    $this->submit = fn (array $overrides = []): QuotationRequest => $this->requests->submit(array_merge([
        'project_type' => QuotationProjectType::NewBuild->value,
        'farm_type' => 'Layer poultry',
        'target_capacity' => 20000,
        'capacity_unit' => 'birds',
        'owns_land' => true,
        'land_size' => 3,
        'land_unit' => 'hectares',
        'state' => 'Oyo',
        'lga' => 'Akinyele',
        'scope_wanted' => ['construction', 'equipment_supply'],
    ], $overrides), $this->client);

    /*
     * One dynamic stub, set up once.
     *
     * Http::fake MERGES stubs rather than replacing them, so re-faking inside a
     * helper leaves the first stub matching every later call — which hands two
     * different orders the same gateway id and trips the unique index. This
     * reads the reference out of the URL and answers for whichever order is
     * actually being verified.
     */
    $gatewayId = 1000;

    Http::fake([
        '*/transaction/verify/*' => function ($request) use (&$gatewayId) {
            $reference = basename(parse_url($request->url(), PHP_URL_PATH));
            $order = Order::query()->where('reference', $reference)->first();

            return Http::response([
                'status' => true,
                'data' => [
                    'id' => ++$gatewayId,
                    'reference' => $reference,
                    'status' => 'success',
                    'amount' => $order?->grand_total_kobo ?? 0,
                    'currency' => $order?->currency ?? 'NGN',
                    'paid_at' => now()->toIso8601String(),
                ],
            ]);
        },
    ]);

    $this->payStudyFee = function (QuotationRequest $request): Order {
        $order = $this->checkout->begin($this->client, $request);

        app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);

        return $order->refresh();
    };
});

// ---------------------------------------------------------------------------
// The gate itself
// ---------------------------------------------------------------------------

it('keeps an unpaid request out of the work queue', function () {
    ($this->submit)();

    expect(QuotationRequest::query()->workable()->count())->toBe(0);
});

it('puts the request into the work queue the moment the fee clears', function () {
    $request = ($this->submit)();

    ($this->payStudyFee)($request);

    $request->refresh();

    expect($request->status)->toBe(QuotationRequestStatus::StudyFeePaid)
        ->and($request->studyFeePaid())->toBeTrue()
        ->and($request->isWorkable())->toBeTrue()
        ->and(QuotationRequest::query()->workable()->count())->toBe(1);
});

it('refuses to start a proposal until the fee has been paid', function () {
    $request = ($this->submit)();

    expect(fn () => $this->quotations->startDraft($request, $this->admin))
        ->toThrow(RuntimeException::class, 'study fee has not been paid');
});

it('allows a proposal once the fee has been paid', function () {
    $request = ($this->submit)();
    ($this->payStudyFee)($request);

    $draft = $this->quotations->startDraft($request->fresh(), $this->admin);

    expect($draft->version)->toBe(1)
        ->and($draft->isDraft())->toBeTrue()
        ->and($request->fresh()->status)->toBe(QuotationRequestStatus::InPreparation);
});

// ---------------------------------------------------------------------------
// The money
// ---------------------------------------------------------------------------

it('books the study fee to the platform as released, with nothing held', function () {
    $request = ($this->submit)();
    ($this->payStudyFee)($request);

    $entry = WalletTransaction::query()
        ->where('type', LedgerType::QuotationStudyFee)
        ->firstOrFail();

    // The company did the work itself: there is nobody to split with and
    // nobody to hold it from.
    expect($entry->user_id)->toBeNull()
        ->and($entry->state)->toBe(LedgerState::Released)
        ->and($entry->amount_kobo)->toBe($request->fresh()->studyFee->amount_kobo)
        ->and($entry->meta['quotation_request_reference'] ?? null)->toBe($request->reference);
});

it('settles a redelivered webhook exactly once', function () {
    $request = ($this->submit)();
    $order = $this->checkout->begin($this->client, $request);

    $processor = app(PaymentProcessor::class);

    expect($processor->markPaidFromReference('paystack', $order->reference))->toBeTrue()
        ->and($processor->markPaidFromReference('paystack', $order->reference))->toBeFalse()
        ->and(WalletTransaction::query()->where('type', LedgerType::QuotationStudyFee)->count())->toBe(1);
});

it('reuses an abandoned order rather than leaving a trail of references', function () {
    $request = ($this->submit)();

    $first = $this->checkout->begin($this->client, $request);
    $second = $this->checkout->begin($this->client, $request->fresh());

    expect($second->id)->toBe($first->id);
});

it('refuses to charge a fee twice', function () {
    $request = ($this->submit)();
    ($this->payStudyFee)($request);

    expect(fn () => $this->checkout->begin($this->client, $request->fresh()))
        ->toThrow(RuntimeException::class, 'already been paid');
});

it('will not let one client pay for another client\'s request', function () {
    $request = ($this->submit)();
    $stranger = User::factory()->create();

    expect(fn () => $this->checkout->begin($stranger, $request))
        ->toThrow(RuntimeException::class, 'not your request');
});

// ---------------------------------------------------------------------------
// The credit trail — the whole reason the fee is its own table
// ---------------------------------------------------------------------------

it('records who decided what happened to the fee, and when', function () {
    $request = ($this->submit)();
    ($this->payStudyFee)($request);

    $fee = $this->fees->recordCredit(
        $request->fresh()->studyFee,
        StudyFeeCreditStatus::Credited,
        $this->admin,
        'Signed the build on 3 March; taken off the first invoice.',
    );

    expect($fee->credit_status)->toBe(StudyFeeCreditStatus::Credited)
        ->and($fee->credited_by)->toBe($this->admin->id)
        ->and($fee->credited_at)->not->toBeNull()
        ->and($fee->credit_note)->toContain('first invoice')
        ->and($fee->isDecided())->toBeTrue();
});

it('refuses to credit a fee without saying why', function () {
    $request = ($this->submit)();
    ($this->payStudyFee)($request);

    // A decision with no reason recorded is exactly the record that fails to
    // settle an argument six months later.
    expect(fn () => $this->fees->recordCredit(
        $request->fresh()->studyFee,
        StudyFeeCreditStatus::Credited,
        $this->admin,
    ))->toThrow(RuntimeException::class, 'Say why');
});

it('lets a fee be left uncredited without an explanation', function () {
    $request = ($this->submit)();
    ($this->payStudyFee)($request);

    // Leaving it uncredited needs no reason: that is simply what was paid for.
    $fee = $this->fees->recordCredit(
        $request->fresh()->studyFee,
        StudyFeeCreditStatus::Uncredited,
        $this->admin,
    );

    expect($fee->credit_status)->toBe(StudyFeeCreditStatus::Uncredited)
        ->and($fee->isDecided())->toBeTrue();
});

it('refuses to credit a fee nobody has paid', function () {
    $request = ($this->submit)();

    expect(fn () => $this->fees->recordCredit(
        $request->studyFee,
        StudyFeeCreditStatus::Credited,
        $this->admin,
        'Nothing to credit.',
    ))->toThrow(RuntimeException::class, 'has not been paid');
});

it('records a later change of mind against the person who changed it', function () {
    $request = ($this->submit)();
    ($this->payStudyFee)($request);

    $fee = $request->fresh()->studyFee;

    $this->fees->recordCredit($fee, StudyFeeCreditStatus::Credited, $this->admin, 'Signed.');

    $second = User::factory()->create(['name' => 'Bola Admin']);
    $second->assignRole(RoleName::Admin->value);

    $changed = $this->fees->recordCredit($fee->fresh(), StudyFeeCreditStatus::Refunded, $second, 'Project fell through; refunded.');

    expect($changed->credit_status)->toBe(StudyFeeCreditStatus::Refunded)
        ->and($changed->credited_by)->toBe($second->id)
        ->and($changed->credit_note)->toContain('fell through');
});

// ---------------------------------------------------------------------------
// The report
// ---------------------------------------------------------------------------

it('counts collected against credited, and treats a refund as the only money that left', function () {
    $credited = ($this->submit)();
    ($this->payStudyFee)($credited);
    $this->fees->recordCredit($credited->fresh()->studyFee, StudyFeeCreditStatus::Credited, $this->admin, 'Signed.');

    $refunded = ($this->submit)();
    ($this->payStudyFee)($refunded);
    $this->fees->recordCredit($refunded->fresh()->studyFee, StudyFeeCreditStatus::Refunded, $this->admin, 'Gave it back.');

    $kept = ($this->submit)();
    ($this->payStudyFee)($kept);

    // Never paid: must not appear anywhere in the tally.
    ($this->submit)();

    $tally = $this->fees->tally();
    $one = $credited->fresh()->studyFee->amount_kobo;

    expect($tally['collected_count'])->toBe(3)
        ->and($tally['collected_kobo'])->toBe($one * 3)
        ->and($tally['credited_kobo'])->toBe($one)
        ->and($tally['refunded_kobo'])->toBe($one)
        ->and($tally['uncredited_kobo'])->toBe($one)
        // A credited fee was still collected — it was discounted against an
        // invoice raised somewhere else entirely.
        ->and($tally['retained_kobo'])->toBe($one * 2);
});

it('leaves the tally at zero when nothing has been paid', function () {
    ($this->submit)();

    $tally = $this->fees->tally();

    expect($tally['collected_kobo'])->toBe(0)
        ->and($tally['collected_count'])->toBe(0)
        ->and($tally['retained_kobo'])->toBe(0);
});
