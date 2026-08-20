<?php

use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Enums\RoleName;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\Branding\BrandingService;
use App\Services\Payments\PaymentProcessor;
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
 * Writing, revising and sending a proposal.
 *
 * The rule under test throughout is that a sent proposal is immutable. Input
 * prices here move fast enough that a document the client is holding — and may
 * have taken to a bank — has to keep saying exactly what it said.
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

    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->admin->assignRole(RoleName::Admin->value);

    $this->client = User::factory()->create(['email' => 'client@example.test']);

    $gatewayId = 2000;

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

    /** A request with its study fee already cleared — i.e. real work. */
    $this->paidRequest = function (): QuotationRequest {
        $request = app(QuotationRequestService::class)->submit([
            'project_type' => QuotationProjectType::NewBuild->value,
            'farm_type' => 'Layer poultry',
            'target_capacity' => 20000,
            'capacity_unit' => 'birds',
            'owns_land' => true,
            'state' => 'Oyo',
            'scope_wanted' => ['construction', 'equipment_supply'],
        ], $this->client);

        $order = app(StudyFeeCheckout::class)->begin($this->client, $request);
        app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);

        return $request->fresh();
    };

    /** A priced draft, ready to send. */
    $this->draftFor = function (QuotationRequest $request, int $unitPriceKobo = 1_200_000): Quotation {
        $draft = $this->quotations->startDraft($request, $this->admin, [
            'title' => '20,000-bird layer farm — Akinyele, Oyo',
            'executive_summary' => 'A phased build on the three hectares you own.',
            'assumptions' => 'Access road is passable by a tipper in the rains.',
            'exclusions' => 'Land purchase, perimeter fencing, borehole.',
        ]);

        $draft->lineItems()->create([
            'section' => 'Housing',
            'description' => 'Layer house, 60m × 12m, block to sill',
            'quantity' => 2,
            'unit' => 'houses',
            'unit_price_kobo' => $unitPriceKobo,
            'sort_order' => 1,
        ]);

        $draft->lineItems()->create([
            'section' => 'Equipment',
            'description' => 'Battery cages, 96-bird tier',
            'quantity' => 10,
            'unit' => 'sets',
            'unit_price_kobo' => 400_000,
            'sort_order' => 2,
        ]);

        $draft->forceFill(['contingency_percent' => 10]);
        $draft->load('lineItems')->recalculate()->save();

        return $draft;
    };
});

// ---------------------------------------------------------------------------
// Totals
// ---------------------------------------------------------------------------

it('adds up the lines and puts a contingency on top', function () {
    $draft = ($this->draftFor)(($this->paidRequest)());

    // 2 × 12,000 + 10 × 4,000 = 64,000, plus 10% = 70,400.
    expect($draft->subtotal_kobo)->toBe(6_400_000)
        ->and($draft->contingency_kobo)->toBe(640_000)
        ->and($draft->total_kobo)->toBe(7_040_000);
});

it('keeps a line total honest with its own quantity and price', function () {
    $draft = ($this->draftFor)(($this->paidRequest)());

    $line = $draft->lineItems->first();
    $line->update(['quantity' => 5]);

    // Recomputed on save rather than taken from a form, so a total that does
    // not match the numbers beside it cannot be written.
    expect($line->fresh()->total_kobo)->toBe(5 * 1_200_000);
});

it('rounds the contingency to the naira rather than the kobo', function () {
    $request = ($this->paidRequest)();
    $draft = $this->quotations->startDraft($request, $this->admin);

    $draft->lineItems()->create([
        'section' => 'Odds',
        'description' => 'Something awkward',
        'quantity' => 1,
        'unit_price_kobo' => 333_333,
    ]);

    $draft->forceFill(['contingency_percent' => 7.5]);
    $draft->load('lineItems')->recalculate();

    // A contingency is an estimate of an estimate; quoting it to the kobo is
    // false precision.
    expect($draft->contingency_kobo % 100)->toBe(0);
});

// ---------------------------------------------------------------------------
// Sending
// ---------------------------------------------------------------------------

it('sets the validity date from the send date and the setting', function () {
    $this->settings->set('quote_validity_days', 21, 'int', 'platform');

    $this->travelTo(now()->startOfDay()->addHours(10));

    $sent = $this->quotations->send(($this->draftFor)(($this->paidRequest)()), $this->admin);

    expect($sent->valid_until->toDateString())->toBe(now()->addDays(21)->toDateString())
        ->and($sent->status)->toBe(QuotationStatus::Sent)
        ->and($sent->sent_at)->not->toBeNull();
});

it('freezes the company name onto the record at send time', function () {
    $branding = app(BrandingService::class);
    $this->settings->set('company_name', 'Green Acres Limited', 'string', 'branding');
    $branding->flush();

    $sent = $this->quotations->send(($this->draftFor)(($this->paidRequest)()), $this->admin);

    // The company renames itself.
    $this->settings->set('company_name', 'Green Acres Agro Nigeria Limited', 'string', 'branding');
    $branding->flush();

    // Reissuing March's proposal must produce March's proposal, not one
    // wearing this quarter's name.
    expect($sent->fresh()->issuer_name)->toBe('Green Acres Limited')
        ->and($branding->name())->toBe('Green Acres Agro Nigeria Limited');
});

it('moves the request to sent and stores the PDF', function () {
    $request = ($this->paidRequest)();
    $sent = $this->quotations->send(($this->draftFor)($request), $this->admin);

    expect($request->fresh()->status)->toBe(QuotationRequestStatus::QuoteSent)
        ->and($sent->pdf_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($sent->pdf_path))->toBeTrue();
});

it('refuses to send a proposal with no priced lines', function () {
    $request = ($this->paidRequest)();
    $empty = $this->quotations->startDraft($request, $this->admin);

    expect(fn () => $this->quotations->send($empty, $this->admin))
        ->toThrow(RuntimeException::class, 'no priced lines');
});

it('refuses to send the same version twice', function () {
    $sent = $this->quotations->send(($this->draftFor)(($this->paidRequest)()), $this->admin);

    expect(fn () => $this->quotations->send($sent, $this->admin))
        ->toThrow(RuntimeException::class, 'already been sent');
});

// ---------------------------------------------------------------------------
// Revisions — the heart of it
// ---------------------------------------------------------------------------

it('creates a new version rather than editing the one that went out', function () {
    $request = ($this->paidRequest)();
    $first = $this->quotations->send(($this->draftFor)($request), $this->admin);

    $revision = $this->quotations->reviseFrom($first->fresh(), $this->admin);

    expect($revision->version)->toBe(2)
        ->and($revision->id)->not->toBe($first->id)
        ->and($revision->isDraft())->toBeTrue();
});

it('copies the prose and the lines into the revision', function () {
    $request = ($this->paidRequest)();
    $first = $this->quotations->send(($this->draftFor)($request), $this->admin);

    $revision = $this->quotations->reviseFrom($first->fresh(), $this->admin);

    // The usual reason for a revision is that two prices moved, not that
    // everything was wrong.
    expect($revision->executive_summary)->toBe($first->executive_summary)
        ->and($revision->exclusions)->toBe($first->exclusions)
        ->and($revision->lineItems)->toHaveCount($first->lineItems()->count())
        ->and($revision->total_kobo)->toBe($first->total_kobo);
});

it('leaves the previous version live until the revision is actually sent', function () {
    $request = ($this->paidRequest)();
    $first = $this->quotations->send(($this->draftFor)($request), $this->admin);

    $this->quotations->reviseFrom($first->fresh(), $this->admin);

    // A client must not be left holding a proposal marked "replaced" by
    // something that does not exist yet.
    expect($first->fresh()->status)->toBe(QuotationStatus::Sent)
        ->and($request->fresh()->currentQuotation->version)->toBe(1);
});

it('supersedes the previous version once the revision goes out', function () {
    $request = ($this->paidRequest)();
    $first = $this->quotations->send(($this->draftFor)($request), $this->admin);

    $revision = $this->quotations->reviseFrom($first->fresh(), $this->admin);
    $revision->lineItems->first()->update(['unit_price_kobo' => 1_500_000]);
    $revision->load('lineItems')->recalculate()->save();

    $this->quotations->send($revision->fresh(), $this->admin);

    expect($first->fresh()->status)->toBe(QuotationStatus::Superseded)
        ->and($request->fresh()->currentQuotation->version)->toBe(2);
});

it('preserves the superseded version exactly as the client received it', function () {
    $request = ($this->paidRequest)();
    $first = $this->quotations->send(($this->draftFor)($request), $this->admin);

    $originalTotal = $first->total_kobo;
    $originalValidUntil = $first->valid_until->toDateString();

    $revision = $this->quotations->reviseFrom($first->fresh(), $this->admin);
    $revision->lineItems->first()->update(['unit_price_kobo' => 9_900_000]);
    $revision->load('lineItems')->recalculate()->save();
    $this->quotations->send($revision->fresh(), $this->admin);

    $first->refresh();

    expect($first->total_kobo)->toBe($originalTotal)
        ->and($first->valid_until->toDateString())->toBe($originalValidUntil)
        ->and($first->lineItems->first()->unit_price_kobo)->toBe(1_200_000);
});

it('shows the client the whole history but never a draft', function () {
    $request = ($this->paidRequest)();
    $first = $this->quotations->send(($this->draftFor)($request), $this->admin);

    $revision = $this->quotations->reviseFrom($first->fresh(), $this->admin);
    $revision->lineItems->first()->update(['unit_price_kobo' => 1_500_000]);
    $revision->load('lineItems')->recalculate()->save();
    $this->quotations->send($revision->fresh(), $this->admin);

    // A third, still being written.
    $this->quotations->reviseFrom($request->fresh()->currentQuotation, $this->admin);

    $visible = $request->fresh()->visibleQuotations()->pluck('version')->all();

    expect($visible)->toEqualCanonicalizing([1, 2])
        ->and($request->fresh()->quotations()->count())->toBe(3);
});

it('refuses to open two drafts against one request', function () {
    $request = ($this->paidRequest)();
    $first = $this->quotations->send(($this->draftFor)($request), $this->admin);

    $a = $this->quotations->reviseFrom($first->fresh(), $this->admin);
    $b = $this->quotations->reviseFrom($first->fresh(), $this->admin);

    // Two people writing two drafts against one request is not a version
    // history, it is a mistake waiting to be sent to a client.
    expect($b->id)->toBe($a->id);
});

it('refuses to revise a draft', function () {
    $request = ($this->paidRequest)();
    $draft = ($this->draftFor)($request);

    expect(fn () => $this->quotations->reviseFrom($draft, $this->admin))
        ->toThrow(RuntimeException::class, 'has not been sent yet');
});

it('reuses the number of a draft that was deleted before anybody saw it', function () {
    $request = ($this->paidRequest)();
    $first = $this->quotations->send(($this->draftFor)($request), $this->admin);

    $abandoned = $this->quotations->reviseFrom($first->fresh(), $this->admin);
    $abandoned->delete();

    $next = $this->quotations->reviseFrom($first->fresh(), $this->admin);

    /*
     * Version numbers are what a client and the company say to each other on
     * the phone, so a gap in them invites "what happened to version two?" —
     * about a document that never existed outside this office. A draft has no
     * PDF and is never visible to the client, so nothing ever bore the number
     * and reusing it costs nothing.
     */
    expect($next->version)->toBe(2)
        ->and($request->fresh()->quotations()->count())->toBe(2);
});
