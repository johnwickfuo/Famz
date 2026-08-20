<?php

use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Enums\RoleName;
use App\Enums\StudyFeeCreditStatus;
use App\Filament\Admin\Pages\StudyFees;
use App\Filament\Admin\Resources\QuotationRequests\Pages\ListQuotationRequests;
use App\Filament\Admin\Resources\QuotationRequests\Pages\ViewQuotationRequest;
use App\Filament\Admin\Resources\QuotationRequests\QuotationRequestResource;
use App\Filament\Admin\Resources\QuotationRequests\RelationManagers\QuotationsRelationManager;
use App\Mail\QuotationSentMail;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\Payments\PaymentProcessor;
use App\Services\Quotations\QuotationRequestService;
use App\Services\Quotations\QuotationService;
use App\Services\Quotations\StudyFeeCheckout;
use App\Services\Quotations\StudyFeeService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

/**
 * The queue an administrator works farm-setup requests from.
 *
 * The thing being tested is that the gate is visible and enforced on the
 * screen, not just in the service: an unpaid request must not look like work,
 * and a sent proposal must not look editable.
 */
beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
    Storage::fake('public');

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->admin->assignRole(RoleName::Admin->value);
    $this->actingAs($this->admin);

    $this->client = User::factory()->create(['name' => 'Ngozi Okeke', 'email' => 'client@example.test']);

    $this->quotations = app(QuotationService::class);

    $gatewayId = 4000;

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

    $this->submit = fn (): QuotationRequest => app(QuotationRequestService::class)->submit([
        'project_type' => QuotationProjectType::NewBuild->value,
        'farm_type' => 'Layer poultry',
        'target_capacity' => 20000,
        'capacity_unit' => 'birds',
        'owns_land' => true,
        'state' => 'Oyo',
        'lga' => 'Akinyele',
        'scope_wanted' => ['construction'],
    ], $this->client);

    $this->paid = function (): QuotationRequest {
        $request = ($this->submit)();
        $order = app(StudyFeeCheckout::class)->begin($this->client, $request);
        app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);

        return $request->fresh();
    };

    $this->priced = function (QuotationRequest $request): Quotation {
        $draft = $this->quotations->startDraft($request, $this->admin, [
            'title' => '20,000-bird layer farm',
        ]);

        $draft->lineItems()->create([
            'section' => 'Housing',
            'description' => 'Layer house',
            'quantity' => 2,
            'unit_price_kobo' => 1_200_000,
        ]);

        $draft->load('lineItems')->recalculate()->save();

        return $draft->fresh();
    };
});

// ---------------------------------------------------------------------------
// The queue
// ---------------------------------------------------------------------------

it('opens on the paid work when there is any', function () {
    ($this->paid)();

    expect(livewire(ListQuotationRequests::class)->instance()->getDefaultActiveTab())->toBe('to_write');
});

it('opens on everything open when nothing has been paid for', function () {
    ($this->submit)();

    expect(livewire(ListQuotationRequests::class)->instance()->getDefaultActiveTab())->toBe('open');
});

it('keeps unpaid requests off the work tab', function () {
    $unpaid = ($this->submit)();
    $paid = ($this->paid)();

    livewire(ListQuotationRequests::class)
        ->set('activeTab', 'to_write')
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$unpaid]);
});

it('counts only paid work in the navigation badge', function () {
    ($this->submit)();

    expect(QuotationRequestResource::getNavigationBadge())->toBeNull();

    ($this->paid)();

    // A badge counting enquiries is a number people stop reading; this one
    // counts money already taken against a proposal not yet sent.
    expect(QuotationRequestResource::getNavigationBadge())->toBe('1');
});

it('renders the request view page with the fee front and centre', function () {
    $request = ($this->paid)();

    livewire(ViewQuotationRequest::class, ['record' => $request->getRouteKey()])
        ->assertOk()
        ->assertSee('The study fee')
        ->assertSee('Ngozi Okeke');
});

it('closes a request as won for reporting only', function () {
    $request = ($this->paid)();

    livewire(ListQuotationRequests::class)->callAction(
        TestAction::make('accept')->table($request),
        ['note' => 'Signed on 3 March.'],
    );

    $request->refresh();

    expect($request->status)->toBe(QuotationRequestStatus::AcceptedOffline)
        ->and($request->closed_at)->not->toBeNull()
        ->and($request->outcome_note)->toContain('3 March');
});

it('records why a request was lost', function () {
    $request = ($this->paid)();

    livewire(ListQuotationRequests::class)->callAction(
        TestAction::make('decline')->table($request),
        ['note' => 'Went with a cheaper builder.'],
    );

    // Lost reasons are the only feedback on pricing this company gets.
    expect($request->fresh()->status)->toBe(QuotationRequestStatus::Declined)
        ->and($request->fresh()->outcome_note)->toContain('cheaper builder');
});

// ---------------------------------------------------------------------------
// The credit control
// ---------------------------------------------------------------------------

it('records the fee decision with the acting administrator', function () {
    $request = ($this->paid)();

    livewire(ViewQuotationRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('recordCredit', [
            'credit_status' => StudyFeeCreditStatus::Credited->value,
            'credit_note' => 'Taken off the first invoice.',
        ]);

    $fee = $request->fresh()->studyFee;

    expect($fee->credit_status)->toBe(StudyFeeCreditStatus::Credited)
        ->and($fee->credited_by)->toBe($this->admin->id)
        ->and($fee->credited_at)->not->toBeNull();
});

it('refuses a credit decision with no reason and leaves the fee alone', function () {
    $request = ($this->paid)();

    livewire(ViewQuotationRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('recordCredit', [
            'credit_status' => StudyFeeCreditStatus::Credited->value,
            'credit_note' => '',
        ]);

    expect($request->fresh()->studyFee->credit_status)->toBe(StudyFeeCreditStatus::Uncredited)
        ->and($request->fresh()->studyFee->isDecided())->toBeFalse();
});

it('hides the credit control until the fee has been paid', function () {
    $request = ($this->submit)();

    livewire(ViewQuotationRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionHidden('recordCredit');
});

// ---------------------------------------------------------------------------
// The builder
// ---------------------------------------------------------------------------

it('offers no way to start a proposal before the fee clears', function () {
    $request = ($this->submit)();

    livewire(QuotationsRelationManager::class, [
        'ownerRecord' => $request,
        'pageClass' => ViewQuotationRequest::class,
    ])->assertActionHidden(TestAction::make('create')->table());
});

it('lets an administrator start a proposal once the fee clears', function () {
    $request = ($this->paid)();

    livewire(QuotationsRelationManager::class, [
        'ownerRecord' => $request,
        'pageClass' => ViewQuotationRequest::class,
    ])->assertActionVisible(TestAction::make('create')->table());
});

it('sends a priced draft and emails the client', function () {
    $request = ($this->paid)();
    $draft = ($this->priced)($request);

    livewire(QuotationsRelationManager::class, [
        'ownerRecord' => $request,
        'pageClass' => ViewQuotationRequest::class,
    ])->callAction(TestAction::make('send')->table($draft));

    $draft->refresh();

    expect($draft->status)->toBe(QuotationStatus::Sent)
        ->and($draft->valid_until)->not->toBeNull()
        ->and($draft->issuer_name)->not->toBeNull();

    Mail::assertQueued(QuotationSentMail::class);
});

it('will not offer to send a proposal that has already gone', function () {
    $request = ($this->paid)();
    $sent = $this->quotations->send(($this->priced)($request), $this->admin);

    livewire(QuotationsRelationManager::class, [
        'ownerRecord' => $request,
        'pageClass' => ViewQuotationRequest::class,
    ])->assertActionHidden(TestAction::make('send')->table($sent));
});

it('will not offer to edit a proposal that has already gone', function () {
    $request = ($this->paid)();
    $sent = $this->quotations->send(($this->priced)($request), $this->admin);

    // Editing a sent proposal would change a document somebody is holding.
    livewire(QuotationsRelationManager::class, [
        'ownerRecord' => $request,
        'pageClass' => ViewQuotationRequest::class,
    ])->assertActionHidden(TestAction::make('edit')->table($sent));
});

it('starts a revision from the version that went out', function () {
    $request = ($this->paid)();
    $sent = $this->quotations->send(($this->priced)($request), $this->admin);

    livewire(QuotationsRelationManager::class, [
        'ownerRecord' => $request,
        'pageClass' => ViewQuotationRequest::class,
    ])->callAction(TestAction::make('revise')->table($sent));

    expect($request->fresh()->quotations()->count())->toBe(2)
        ->and($request->fresh()->latestQuotation->version)->toBe(2)
        ->and($request->fresh()->latestQuotation->isDraft())->toBeTrue();
});

// ---------------------------------------------------------------------------
// The money report
// ---------------------------------------------------------------------------

it('reports study fees collected against credited', function () {
    $credited = ($this->paid)();
    app(StudyFeeService::class)->recordCredit(
        $credited->studyFee,
        StudyFeeCreditStatus::Credited,
        $this->admin,
        'Signed.',
    );

    ($this->paid)();

    $data = livewire(StudyFees::class)->instance()->getViewData();

    expect($data['tally']['collected_count'])->toBe(2)
        ->and($data['credited_share'])->toBe(50.0)
        // The one nobody has decided about is the row that becomes an argument.
        ->and($data['undecided'])->toHaveCount(1);
});

it('shows a dash rather than dividing by zero when nothing has been collected', function () {
    ($this->submit)();

    $data = livewire(StudyFees::class)->instance()->getViewData();

    expect($data['credited_share'])->toBeNull()
        ->and($data['undecided'])->toBeEmpty();
});
