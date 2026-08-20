<?php

use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationScope;
use App\Enums\StudyFeeCreditStatus;
use App\Mail\QuotationStudyFeeDueMail;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\Quotations\QuotationRequestService;
use App\Services\Quotations\StudyFeeService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Asking for a farm to be costed.
 *
 * The thing being tested here is the gate: a request is an enquiry until the
 * study fee clears, and nothing about the shape of the form changes that.
 */
beforeEach(function (): void {
    Mail::fake();
    Storage::fake('public');

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->client = User::factory()->create(['email' => 'client@example.test']);

    $this->requests = app(QuotationRequestService::class);
    $this->fees = app(StudyFeeService::class);

    $this->brief = fn (array $overrides = []): array => array_merge([
        'project_type' => QuotationProjectType::NewBuild->value,
        'farm_type' => 'Layer poultry',
        'target_capacity' => 20000,
        'capacity_unit' => 'birds',
        'owns_land' => true,
        'land_size' => 3,
        'land_unit' => 'hectares',
        'state' => 'Oyo',
        'lga' => 'Akinyele',
        'scope_wanted' => [
            QuotationScope::Construction->value,
            QuotationScope::EquipmentSupply->value,
        ],
    ], $overrides);
});

it('takes a brief and raises the study fee immediately', function () {
    $request = $this->requests->submit(($this->brief)(), $this->client);

    expect($request->reference)->toStartWith('QR-')
        ->and($request->user_id)->toBe($this->client->id)
        ->and($request->project_type)->toBe(QuotationProjectType::NewBuild)
        ->and($request->target_capacity)->toBe(20000)
        // Raised, not merely available: a request must never sit in a state
        // where it looks like paid work but nothing has been charged.
        ->and($request->studyFee)->not->toBeNull()
        ->and($request->status)->toBe(QuotationRequestStatus::StudyFeePending);
});

it('copies the fee amount onto the row rather than reading settings later', function () {
    $request = $this->requests->submit(($this->brief)(), $this->client);
    $charged = $request->studyFee->amount_kobo;

    // The company puts its prices up.
    settings()->set('quotation_study_fee', 9_000_000, 'int', 'platform');

    expect($request->studyFee->fresh()->amount_kobo)->toBe($charged)
        ->and($this->fees->currentAmountKobo())->toBe(9_000_000);
});

it('stores a budget range as kobo and reads it back as a range', function () {
    $request = $this->requests->submit(($this->brief)([
        'budget_range_min' => 40_000_000,
        'budget_range_max' => 60_000_000,
    ]), $this->client);

    expect($request->budget_range_min_kobo)->toBe(4_000_000_000)
        ->and($request->budget_range_max_kobo)->toBe(6_000_000_000)
        ->and($request->budgetRange())->toContain('–');
});

it('keeps an unstated budget null rather than turning it into zero', function () {
    // "No budget stated" and "a budget of nothing" are different answers, and
    // a proposal written against the second would be nonsense.
    $request = $this->requests->submit(($this->brief)(['budget_range_min' => '', 'budget_range_max' => null]), $this->client);

    expect($request->budget_range_min_kobo)->toBeNull()
        ->and($request->budget_range_max_kobo)->toBeNull()
        ->and($request->budgetRange())->toBeNull();
});

it('says the budget honestly when only one end is known', function () {
    $only = $this->requests->submit(($this->brief)(['budget_range_max' => 60_000_000]), $this->client);

    expect($only->budgetRange())->toContain('Up to');
});

it('keeps the scope list as tags rather than prose', function () {
    $request = $this->requests->submit(($this->brief)(), $this->client);

    expect($request->scope_wanted)->toBe(['construction', 'equipment_supply'])
        ->and($request->scopeLabels())->toContain('Construction');
});

it('ignores a scope value that no longer exists', function () {
    $request = $this->requests->submit(($this->brief)(['scope_wanted' => ['construction', 'telepathy']]), $this->client);

    // A request written before a case was renamed must not blow up a dashboard.
    expect($request->scopeLabels())->toBe(['Construction']);
});

it('submits through the form and emails the fee notice', function () {
    $response = $this->actingAs($this->client)->post(route('quotations.store'), ($this->brief)());

    $request = QuotationRequest::query()->firstOrFail();

    $response->assertRedirect(route('quotations.show', $request->reference));

    Mail::assertQueued(
        QuotationStudyFeeDueMail::class,
        fn ($mail): bool => $mail->request->reference === $request->reference,
    );
});

it('refuses a budget range that runs backwards', function () {
    $this->actingAs($this->client)
        ->post(route('quotations.store'), ($this->brief)([
            'budget_range_min' => 60_000_000,
            'budget_range_max' => 40_000_000,
        ]))
        ->assertSessionHasErrors('budget_range_max');
});

it('requires an account', function () {
    // Unlike a consultation. A study fee has to be paid before anything
    // happens, and paying means somebody the company can come back to.
    $this->post(route('quotations.store'), ($this->brief)())->assertRedirect(route('login'));
});

it('will not let one client read another client\'s request', function () {
    $request = $this->requests->submit(($this->brief)(), $this->client);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('quotations.show', $request->reference))
        ->assertForbidden();
});

it('raises the fee once however many times it is asked', function () {
    $request = $this->requests->submit(($this->brief)(), $this->client);

    $again = $this->fees->raiseFor($request);

    expect($again->id)->toBe($request->studyFee->id)
        ->and($request->studyFee()->count())->toBe(1);
});

it('starts every fee undecided rather than uncredited-by-default', function () {
    $request = $this->requests->submit(($this->brief)(), $this->client);

    // Same enum value, different facts: nobody has looked at this yet, and the
    // absence of a timestamp is what says so.
    expect($request->studyFee->credit_status)->toBe(StudyFeeCreditStatus::Uncredited)
        ->and($request->studyFee->isDecided())->toBeFalse();
});
