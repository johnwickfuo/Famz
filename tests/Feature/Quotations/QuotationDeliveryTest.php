<?php

use App\Documents\QuotationProposalDocument;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Enums\RoleName;
use App\Mail\QuotationExpiredMail;
use App\Mail\QuotationSentMail;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Branding\BrandingService;
use App\Services\Payments\PaymentProcessor;
use App\Services\Quotations\QuotationNotifier;
use App\Services\Quotations\QuotationRequestService;
use App\Services\Quotations\QuotationService;
use App\Services\Quotations\StudyFeeCheckout;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Getting the proposal to the client, and taking it back when it lapses.
 *
 * The document is the product. Somebody paid a study fee specifically to come
 * away with something they can print, email to a partner and take to a bank —
 * so unlike course material it is downloadable, unwatermarked, and theirs.
 */
beforeEach(function (): void {
    Mail::fake();
    Storage::fake('local');
    Storage::fake('public');

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    $this->settings = app(SettingsService::class);
    $this->quotations = app(QuotationService::class);
    $this->branding = app(BrandingService::class);

    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->admin->assignRole(RoleName::Admin->value);

    $this->client = User::factory()->create(['name' => 'Ngozi Okeke', 'email' => 'client@example.test']);

    $gatewayId = 3000;

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

    $this->sentProposal = function (): Quotation {
        $request = app(QuotationRequestService::class)->submit([
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
        ], $this->client);

        $order = app(StudyFeeCheckout::class)->begin($this->client, $request);
        app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);

        $draft = $this->quotations->startDraft($request->fresh(), $this->admin, [
            'title' => '20,000-bird layer farm — Akinyele, Oyo',
            'executive_summary' => 'A two-house phased build on the three hectares you own.',
            'scope_of_work' => 'Design, build and equip two layer houses, then stock them.',
            'assumptions' => 'Access road is passable by a tipper in the rains.',
            'exclusions' => 'Land purchase, perimeter fencing, borehole.',
            'timeline_description' => 'Sixteen weeks from mobilisation to first stocking.',
            'payment_terms' => '40% to start, 40% at roofing, 20% on handover.',
        ]);

        $draft->lineItems()->create([
            'section' => 'Site preparation',
            'description' => 'Clear and level the building footprint',
            'quantity' => 1,
            'unit' => 'lot',
            'unit_price_kobo' => 800_000,
            'sort_order' => 1,
        ]);
        $draft->lineItems()->create([
            'section' => 'Housing',
            'description' => 'Layer house, 60m × 12m',
            'quantity' => 2,
            'unit' => 'houses',
            'unit_price_kobo' => 1_200_000,
            'sort_order' => 2,
        ]);
        $draft->lineItems()->create([
            'section' => 'Housing',
            'description' => 'Concrete floor and drainage',
            'quantity' => 1440,
            'unit' => 'm²',
            'unit_price_kobo' => 1_500,
            'sort_order' => 3,
        ]);
        $draft->lineItems()->create([
            'section' => 'Equipment',
            'description' => 'Battery cages, 96-bird tier',
            'quantity' => 10,
            'unit' => 'sets',
            'unit_price_kobo' => 400_000,
            'sort_order' => 4,
        ]);

        $draft->forceFill(['contingency_percent' => 10]);
        $draft->load('lineItems')->recalculate()->save();

        return $this->quotations->send($draft->fresh(), $this->admin);
    };
});

// ---------------------------------------------------------------------------
// The document
// ---------------------------------------------------------------------------

it('renders every section of the proposal', function () {
    $html = (new QuotationProposalDocument(($this->sentProposal)()))->render();

    expect($html)
        // Letterhead and identity
        ->toContain('20,000-bird layer farm')
        ->toContain('QR-')
        ->toContain('Version 1')
        // Who it is for
        ->toContain('Ngozi Okeke')
        ->toContain('Akinyele, Oyo')
        // The prose
        ->toContain('phased build')
        ->toContain('Design, build and equip')
        ->toContain('Access road is passable')
        ->toContain('Land purchase, perimeter fencing')
        ->toContain('Sixteen weeks')
        ->toContain('40% to start')
        // The money
        ->toContain('Site preparation')
        ->toContain('Housing')
        ->toContain('Equipment')
        ->toContain('Battery cages')
        ->toContain('Contingency')
        // The validity and the contact block
        ->toContain('Valid until')
        ->toContain('To go ahead');
});

it('groups the line items under their sections in the admin\'s own order', function () {
    $quotation = ($this->sentProposal)();

    $sections = $quotation->sections();

    // Sections come out in the order their first line appears, because an
    // administrator who put Site preparation before Housing meant it.
    expect($sections->keys()->all())->toBe(['Site preparation', 'Housing', 'Equipment'])
        ->and($sections['Housing'])->toHaveCount(2)
        ->and($quotation->sectionTotals()['Housing'])->toBe(2 * 1_200_000 + 1440 * 1_500);
});

it('prints the frozen company name, not whatever branding says today', function () {
    $this->settings->set('company_name', 'Green Acres Limited', 'string', 'branding');
    $this->branding->flush();

    $quotation = ($this->sentProposal)();

    $this->settings->set('company_name', 'Somebody Else Entirely Limited', 'string', 'branding');
    $this->branding->flush();

    $html = (new QuotationProposalDocument($quotation->fresh()))->render();

    expect($html)->toContain('Green Acres Limited')
        ->not->toContain('Somebody Else Entirely Limited');
});

it('takes the contact block from branding rather than a literal', function () {
    $this->settings->set('company_phone', '0803 111 2222', 'string', 'branding');
    $this->settings->set('company_email', 'build@greenacres.test', 'string', 'branding');
    $this->settings->set('company_rc_number', 'RC 1234567', 'string', 'branding');
    $this->branding->flush();

    $html = (new QuotationProposalDocument(($this->sentProposal)()))->render();

    // Contact details are read live, unlike the name: a stale phone number on
    // a reissued proposal helps nobody.
    expect($html)->toContain('0803 111 2222')
        ->toContain('build@greenacres.test')
        ->toContain('1234567');
});

it('stores the rendered bytes so a reissue is the same document', function () {
    $quotation = ($this->sentProposal)();

    expect(Storage::disk('local')->exists($quotation->pdf_path))->toBeTrue()
        ->and($this->quotations->pdfContents($quotation))->toStartWith('%PDF');
});

it('re-renders rather than failing when the stored file has gone', function () {
    $quotation = ($this->sentProposal)();

    Storage::disk('local')->delete($quotation->pdf_path);

    // A proposal the client cannot download because a file went missing is
    // worse than one rendered fresh from a record that has not changed.
    expect($this->quotations->pdfContents($quotation))->toStartWith('%PDF');
});

// ---------------------------------------------------------------------------
// Delivery and access
// ---------------------------------------------------------------------------

it('emails the client when the proposal is sent', function () {
    $quotation = ($this->sentProposal)();

    app(QuotationNotifier::class)->quotationSent($quotation);

    Mail::assertQueued(
        QuotationSentMail::class,
        fn ($mail): bool => $mail->quotation->id === $quotation->id,
    );
});

it('lets the client download the proposal', function () {
    $quotation = ($this->sentProposal)();

    // Downloadable and unwatermarked, unlike course material: this is the
    // thing they paid for, and its purpose is to be shown to other people.
    $this->actingAs($this->client)
        ->get(route('quotations.proposal', [
            'quotationRequest' => $quotation->request->reference,
            'version' => 1,
        ]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('will not hand the proposal to somebody else', function () {
    $quotation = ($this->sentProposal)();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('quotations.proposal', [
            'quotationRequest' => $quotation->request->reference,
            'version' => 1,
        ]))
        ->assertForbidden();
});

it('will not hand out a draft version', function () {
    $quotation = ($this->sentProposal)();
    $request = $quotation->request;

    $this->quotations->reviseFrom($quotation, $this->admin);

    // A half-priced proposal reaching a client is worse than no proposal.
    $this->actingAs($this->client)
        ->get(route('quotations.proposal', ['quotationRequest' => $request->reference, 'version' => 2]))
        ->assertNotFound();
});

it('shows the client the proposal on their dashboard', function () {
    $quotation = ($this->sentProposal)();

    $this->actingAs($this->client)
        ->get(route('quotations.show', $quotation->request->reference))
        ->assertOk()
        ->assertSee('20,000-bird layer farm', escape: false);
});

// ---------------------------------------------------------------------------
// Lapsing
// ---------------------------------------------------------------------------

it('flags the proposals that are past their date and no others', function () {
    $this->settings->set('quote_validity_days', 30, 'int', 'platform');

    $lapsing = ($this->sentProposal)();
    $fresh = ($this->sentProposal)();

    // Push one of them back so only it is out of date.
    $lapsing->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();

    $this->artisan('quotations:expire')->assertSuccessful();

    expect($lapsing->fresh()->status)->toBe(QuotationStatus::Expired)
        ->and($fresh->fresh()->status)->toBe(QuotationStatus::Sent);
});

it('leaves a proposal alone on its last valid day', function () {
    $quotation = ($this->sentProposal)();
    $quotation->forceFill(['valid_until' => now()->toDateString()])->save();

    // Valid until the 30th means valid all of the 30th.
    $this->artisan('quotations:expire')->assertSuccessful();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Sent);
});

it('follows the request into expiry only if it is still waiting on the client', function () {
    $lapsing = ($this->sentProposal)();
    $lapsing->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();

    $won = ($this->sentProposal)();
    $won->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();
    $this->quotations->close($won->request, QuotationRequestStatus::AcceptedOffline, $this->admin, 'Signed.');

    $this->artisan('quotations:expire');

    expect($lapsing->request->fresh()->status)->toBe(QuotationRequestStatus::Expired)
        // A signed project does not lapse because its paperwork did.
        ->and($won->request->fresh()->status)->toBe(QuotationRequestStatus::AcceptedOffline);
});

it('tells both the client and the company when a proposal lapses', function () {
    $this->settings->set('company_email', 'office@greenacres.test', 'string', 'branding');
    $this->branding->flush();

    $quotation = ($this->sentProposal)();
    $quotation->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();

    $this->artisan('quotations:expire');

    // The client needs to know before they ring quoting it; the company needs
    // to know because a lapsed proposal is a warm lead going cold.
    Mail::assertQueued(QuotationExpiredMail::class, 2);
});

it('does not send the company a second copy when it is also the client', function () {
    $this->settings->set('company_email', $this->client->email, 'string', 'branding');
    $this->branding->flush();

    $quotation = ($this->sentProposal)();
    $quotation->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();

    $this->artisan('quotations:expire');

    Mail::assertQueued(QuotationExpiredMail::class, 1);
});

it('says how long is left in words the client can act on', function () {
    $quotation = ($this->sentProposal)();

    $quotation->forceFill(['valid_until' => now()->addDays(5)->toDateString()])->save();
    expect($quotation->validityCountdown())->toBe('5 days left');

    $quotation->forceFill(['valid_until' => now()->toDateString()])->save();
    expect($quotation->fresh()->validityCountdown())->toBe('Last day');

    $quotation->forceFill(['valid_until' => now()->subDays(3)->toDateString()])->save();
    expect($quotation->fresh()->validityCountdown())->toStartWith('Lapsed');
});
