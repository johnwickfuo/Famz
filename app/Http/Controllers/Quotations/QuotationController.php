<?php

namespace App\Http\Controllers\Quotations;

use App\Documents\QuotationProposalDocument;
use App\Enums\QuotationPowerSituation;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationScope;
use App\Enums\QuotationWaterSource;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Services\Branding\BrandingService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Quotations\QuotationNotifier;
use App\Services\Quotations\QuotationRequestService;
use App\Services\Quotations\QuotationService;
use App\Services\Quotations\StudyFeeCheckout;
use App\Services\Quotations\StudyFeeService;
use App\Support\Money;
use App\Support\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Asking what it would cost to set up a farm, and reading the answer.
 *
 * An account is required, unlike a consultation. A study fee has to be paid
 * before anything happens, and paying means somebody the company can identify
 * and come back to months later — there is no such thing as a guest who can be
 * invoiced after a build.
 *
 * There is deliberately no accept-and-pay flow on the proposal. Going ahead
 * with a farm build is a conversation and a contract, not a button, and
 * pretending otherwise would misrepresent what happens next.
 */
class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationRequestService $requests,
        private readonly QuotationService $quotations,
        private readonly StudyFeeService $fees,
        private readonly StudyFeeCheckout $checkout,
        private readonly QuotationNotifier $notifier,
        private readonly PaymentGatewayManager $gateways,
        private readonly BrandingService $branding,
    ) {}

    /**
     * The brief.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Quotations/Request', [
            'projectTypes' => collect(QuotationProjectType::cases())
                ->map(fn (QuotationProjectType $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                    'hint' => $case->hint(),
                    'needs_land' => $case->needsLand(),
                ])->all(),
            'scopes' => collect(QuotationScope::cases())
                ->map(fn (QuotationScope $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                    'hint' => $case->hint(),
                ])->all(),
            'powerOptions' => QuotationPowerSituation::options(),
            'waterOptions' => QuotationWaterSource::options(),
            'states' => Nigeria::states(),
            'studyFee' => Money::fromKobo($this->fees->currentAmountKobo()),
            // How many they have asked about before. The nav has room for one
            // farm-setup entry and it points here, so this is where somebody
            // coming back looks for their earlier requests.
            'earlierRequests' => QuotationRequest::query()->ownedBy($user)->count(),
            'prefill' => [
                'state' => $user?->profile?->state,
                'lga' => $user?->profile?->lga,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'project_type' => ['required', Rule::enum(QuotationProjectType::class)],
            'farm_type' => ['required', 'string', 'max:120'],

            'target_capacity' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'capacity_unit' => ['nullable', 'string', 'max:40'],

            'owns_land' => ['boolean'],
            'land_size' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'land_unit' => ['nullable', 'string', 'max:24'],

            'state' => ['nullable', 'string', 'max:64'],
            'lga' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],

            'budget_range_min' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'budget_range_max' => ['nullable', 'numeric', 'min:0', 'max:100000000000', 'gte:budget_range_min'],

            'target_start_date' => ['nullable', 'date', 'after_or_equal:today'],

            'scope_wanted' => ['array'],
            'scope_wanted.*' => [Rule::enum(QuotationScope::class)],

            'power_situation' => ['nullable', Rule::enum(QuotationPowerSituation::class)],
            'water_source' => ['nullable', Rule::enum(QuotationWaterSource::class)],

            'additional_notes' => ['nullable', 'string', 'max:4000'],

            'site_photos' => ['array', 'max:8'],
            'site_photos.*' => ['image', 'max:6144'],
        ], [
            'budget_range_max.gte' => __('The top of the range cannot be below the bottom of it.'),
        ]);

        $validated['site_photos'] = collect($request->file('site_photos') ?? [])
            ->map(fn ($file): string => $file->store('quotations/sites', 'public'))
            ->all();

        $quotationRequest = $this->requests->submit($validated, $request->user());

        // The fee notice IS the next step, so it goes out immediately rather
        // than waiting for somebody to notice the request.
        $this->notifier->studyFeeDue($quotationRequest);

        return redirect()
            ->route('quotations.show', $quotationRequest->reference)
            ->with('success', __('We have your project. One step before we start costing it.'));
    }

    /**
     * Everything this person has asked us to price.
     */
    public function index(Request $request): Response
    {
        $requests = QuotationRequest::query()
            ->ownedBy($request->user())
            ->with(['studyFee', 'currentQuotation'])
            ->latest('id')
            ->get()
            ->map(fn (QuotationRequest $item): array => $this->cardPayload($item))
            ->all();

        return Inertia::render('Quotations/Index', [
            'requests' => $requests,
            'studyFee' => Money::fromKobo($this->fees->currentAmountKobo()),
        ]);
    }

    /**
     * One request: where it stands, and the proposal if there is one.
     */
    public function show(Request $request, QuotationRequest $quotationRequest): Response
    {
        abort_unless($quotationRequest->belongsToUser($request->user()), 403);

        $quotationRequest->load([
            'studyFee',
            'visibleQuotations.lineItems',
            'currentQuotation.lineItems',
        ]);

        $current = $quotationRequest->currentQuotation;

        return Inertia::render('Quotations/Show', [
            'request' => [
                ...$this->cardPayload($quotationRequest),
                'farm_type' => $quotationRequest->farm_type,
                'capacity' => $quotationRequest->capacity(),
                'land_size' => $quotationRequest->landSize(),
                'owns_land' => $quotationRequest->owns_land,
                'where' => $quotationRequest->place(),
                'address' => $quotationRequest->address,
                'budget' => $quotationRequest->budgetRange(),
                'target_start_date' => $quotationRequest->target_start_date?->format('j F Y'),
                'scopes' => $quotationRequest->scopeLabels(),
                'power' => $quotationRequest->power_situation?->label(),
                'water' => $quotationRequest->water_source?->label(),
                'notes' => $quotationRequest->additional_notes,
                'photos' => $quotationRequest->sitePhotoUrls(),
            ],
            'studyFee' => $this->studyFeePayload($quotationRequest),
            'quotation' => $current === null ? null : $this->quotationPayload($current),

            /*
             * Every version the client may see, newest first. A client
             * comparing what changed between revisions is doing something
             * entirely reasonable, so the history is not hidden.
             */
            'history' => $quotationRequest->visibleQuotations
                ->map(fn (Quotation $quotation): array => [
                    'version' => $quotation->version,
                    'title' => $quotation->title,
                    'total' => $quotation->total(),
                    'status' => $quotation->status->value,
                    'status_label' => $quotation->status->label(),
                    'tone' => $quotation->status->badgeTone(),
                    'sent_at' => $quotation->sent_at?->format('j M Y'),
                    'is_current' => $current !== null && $quotation->getKey() === $current->getKey(),
                    'pdf_url' => route('quotations.proposal', [
                        'quotationRequest' => $quotationRequest->reference,
                        'version' => $quotation->version,
                    ]),
                ])->values()->all(),

            /*
             * How to say yes. Deliberately a phone number rather than a button:
             * committing to a farm build is a conversation and a contract, and
             * an "Accept" button would misrepresent what happens next.
             */
            'contact' => [
                'name' => $this->branding->name(),
                'phone' => $this->branding->phone(),
                'whatsapp' => $this->branding->whatsapp(),
                'email' => $this->branding->email(),
                'address' => $this->branding->address(),
            ],

            'gateways' => collect($this->gateways->availableFor($quotationRequest->currency))
                ->map(fn ($gateway): array => ['key' => $gateway->key(), 'name' => $gateway->displayName()])
                ->values()->all(),
            'defaultGateway' => (string) settings('active_payment_gateway', 'paystack'),
        ]);
    }

    /**
     * Pay the study fee.
     */
    public function payStudyFee(Request $request, QuotationRequest $quotationRequest): RedirectResponse
    {
        abort_unless($quotationRequest->belongsToUser($request->user()), 403);

        $request->validate([
            'gateway' => ['required', 'string', Rule::in(array_keys(PaymentGatewayManager::GATEWAYS))],
        ]);

        try {
            $order = $this->checkout->begin($request->user(), $quotationRequest);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $gateway = $this->gateways->gateway($request->string('gateway')->toString());

        if (! $gateway->supportsCurrency($order->currency)) {
            return back()->with('error', __(':gateway cannot take payments in :currency.', [
                'gateway' => $gateway->displayName(),
                'currency' => $order->currency,
            ]));
        }

        try {
            $initialisation = $gateway->initialise(
                $order,
                route('checkout.callback', ['reference' => $order->reference]),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __('We could not start the payment. Please try again.'));
        }

        $order->forceFill(['payment_gateway' => $gateway->key()])->save();

        return redirect()->away($initialisation->authorisationUrl);
    }

    /**
     * Download a version of the proposal.
     *
     * A download rather than a stream, and no watermark. Unlike course
     * material, this document is the thing the client paid for: its whole
     * purpose is to be printed, emailed to a partner and taken to a bank.
     * Locking it down would defeat the product.
     */
    public function proposal(Request $request, QuotationRequest $quotationRequest, int $version): HttpResponse
    {
        abort_unless($quotationRequest->belongsToUser($request->user()), 403);

        $quotation = $quotationRequest->visibleQuotations()
            ->where('version', $version)
            ->with('lineItems')
            ->first();

        abort_if($quotation === null, 404);

        return response($this->quotations->pdfContents($quotation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'
                .(new QuotationProposalDocument($quotation))->filename().'"',
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function cardPayload(QuotationRequest $item): array
    {
        $current = $item->currentQuotation;

        return [
            'reference' => $item->reference,
            'project_type' => $item->project_type->label(),
            'farm_type' => $item->farm_type,
            'status' => $item->status->value,
            'status_label' => $item->status->label(),
            'tone' => $item->status->badgeTone(),
            'submitted_at' => $item->created_at?->format('j M Y'),
            'awaits_study_fee' => $item->awaitsStudyFee(),
            'study_fee_paid' => $item->studyFeePaid(),
            'has_quotation' => $current !== null,
            'quotation_total' => $current?->total(),
            'valid_until' => $current?->valid_until?->format('j F Y'),
            'validity_countdown' => $current?->validityCountdown(),
            'is_live' => $current?->isLive() ?? false,
            'url' => route('quotations.show', $item->reference),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function studyFeePayload(QuotationRequest $item): ?array
    {
        $fee = $item->studyFee;

        if ($fee === null) {
            return null;
        }

        return [
            'amount' => $fee->amount(),
            'paid' => $fee->isPaid(),
            'paid_at' => $fee->paid_at?->format('j F Y'),
            // What happened to the fee afterwards is a company matter settled
            // offline; the client is told what it buys, not how it was booked.
            'covers' => __('Costing your project properly: measuring what you need, pricing housing, equipment and stock against your site, and writing it up.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationPayload(Quotation $quotation): array
    {
        return [
            'version' => $quotation->version,
            'title' => $quotation->title,
            'executive_summary' => $quotation->executive_summary,
            'scope_of_work' => $quotation->scope_of_work,
            'assumptions' => $quotation->assumptions,
            'exclusions' => $quotation->exclusions,
            'timeline_description' => $quotation->timeline_description,
            'payment_terms' => $quotation->payment_terms,

            'sections' => $quotation->sections()
                ->map(fn ($items, string $section): array => [
                    'name' => $section,
                    'total' => Money::fromKobo((int) $items->sum('total_kobo')),
                    'items' => $items->map(fn ($item): array => [
                        'description' => $item->description,
                        'quantity' => $item->quantityLabel(),
                        'unit' => $item->unit,
                        'unit_price' => $item->unitPrice(),
                        'total' => $item->total(),
                    ])->values()->all(),
                ])->values()->all(),

            'subtotal' => $quotation->subtotal(),
            'contingency_percent' => rtrim(rtrim(number_format((float) $quotation->contingency_percent, 2), '0'), '.'),
            'contingency' => $quotation->contingency(),
            'total' => $quotation->total(),

            'valid_until' => $quotation->valid_until?->format('j F Y'),
            'validity_countdown' => $quotation->validityCountdown(),
            'is_live' => $quotation->isLive(),
            'is_lapsing_soon' => $quotation->isLapsingSoon(),
            'sent_at' => $quotation->sent_at?->format('j F Y'),
            'status' => $quotation->status->value,
            'status_label' => $quotation->status->label(),
            'tone' => $quotation->status->badgeTone(),

            'pdf_url' => route('quotations.proposal', [
                'quotationRequest' => $quotation->request?->reference,
                'version' => $quotation->version,
            ]),
        ];
    }
}
