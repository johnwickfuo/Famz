<?php

namespace App\Http\Controllers\Consultations;

use App\Documents\ConsultationReportDocument;
use App\Enums\ConsultationTier;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Services\Consultations\ConsultationCheckout;
use App\Services\Consultations\ConsultationNotifier;
use App\Services\Consultations\ConsultationService;
use App\Services\Consultations\ResponseClock;
use App\Services\Payments\PaymentGatewayManager;
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
 * Booking a consultation with the company.
 *
 * Guests are first-class here. Asking a farmer whose birds are dying to
 * register before they can describe the problem loses the booking, so the form
 * takes four fields and no account. The reference goes into their session and
 * into their email, and either one gets them back in.
 */
class ConsultationController extends Controller
{
    /**
     * Session key holding the references booked from this browser.
     *
     * This is what lets a guest reopen their own consultation without an
     * account. It is not a permission — it is a convenience on top of the
     * reference, which is itself long and random.
     */
    private const SESSION_KEY = 'consultations.booked';

    public function __construct(
        private readonly ConsultationService $consultations,
        private readonly ConsultationCheckout $checkout,
        private readonly ConsultationNotifier $notifier,
        private readonly ResponseClock $clock,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * The booking form.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Consultations/Book', [
            'tiers' => $this->consultations->tierPromises(),
            'categories' => $this->categories(),
            'states' => Nigeria::states(),
            'workingHours' => [
                'start' => $this->clock->startHour(),
                'end' => $this->clock->endHour(),
            ],
            // Pre-filled for somebody signed in, but still editable: the person
            // to ring about a problem is not always the account holder.
            'prefill' => $user === null ? null : [
                'full_name' => $user->displayName(),
                'email' => $user->email,
                'phone' => $user->profile?->phone,
                'state' => $user->profile?->state,
                'lga' => $user->profile?->lga,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            /*
             * Four required fields, and that is the whole gate. Everything
             * below them is optional because it is all something we will ask
             * on the phone anyway, and a form that takes thirty seconds is a
             * booking we actually receive.
             */
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
            'tier' => ['required', Rule::in(ConsultationTier::values())],

            'category' => ['nullable', 'string', 'max:120'],
            'situation' => ['nullable', 'string', 'max:5000'],
            'farm_type' => ['nullable', 'string', 'max:120'],
            'animal_type' => ['nullable', 'string', 'max:120'],
            'flock_size' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'state' => ['nullable', 'string', 'max:64'],
            'lga' => ['nullable', 'string', 'max:64'],

            // Compressed in the browser before they get here, so the limit is
            // generous without costing anybody their data bundle.
            'photos' => ['array', 'max:6'],
            'photos.*' => ['image', 'max:4096'],
        ]);

        $paths = collect($request->file('photos') ?? [])
            ->map(fn ($file): string => $file->store('consultations', 'public'))
            ->all();

        $consultation = $this->consultations->book(
            [...$validated, 'attachments' => $paths],
            $request->user(),
        );

        // Remembered so a guest can reopen it from this browser without an
        // account; the emailed reference is the other way back in.
        $request->session()->push(self::SESSION_KEY, $consultation->reference);

        $this->notifier->booked($consultation);

        return redirect()
            ->route('consultations.show', $consultation->reference)
            ->with('success', __('We have it. Somebody will be in touch.'));
    }

    /**
     * One consultation, for the person who booked it.
     */
    public function show(Request $request, Consultation $consultation): Response
    {
        abort_unless($this->maySee($request, $consultation), 403);

        $consultation->load(['publishedReport', 'followups.author']);

        return Inertia::render('Consultations/Show', [
            'consultation' => $this->payload($consultation),
            'report' => $this->reportPayload($consultation),
            'followups' => $consultation->followups
                // Internal notes are the company talking to itself.
                ->reject(fn (ConsultationFollowup $note): bool => $note->is_internal)
                ->map(fn (ConsultationFollowup $note): array => [
                    'id' => $note->id,
                    'message' => $note->message,
                    'from_company' => $note->isFromCompany(),
                    'author' => $note->isFromCompany()
                        ? __('Our team')
                        : ($note->author?->displayName() ?? $consultation->full_name),
                    'attachments' => $note->attachmentUrls(),
                    'at' => $note->created_at->format('j M Y, H:i'),
                ])->values()->all(),
            'followupsOpen' => $consultation->followupsOpen(),
            'followupsCloseAt' => $consultation->followupsCloseAt()?->format('j F Y'),
            'gateways' => collect($this->gateways->availableFor($consultation->currency))
                ->map(fn ($gateway): array => ['key' => $gateway->key(), 'name' => $gateway->displayName()])
                ->values()->all(),
            'defaultGateway' => (string) settings('active_payment_gateway', 'paystack'),
            // Signing in is what makes it survive a lost session, so the page
            // says so rather than leaving a guest to guess.
            'isGuest' => $request->user() === null,
        ]);
    }

    /**
     * Everything this person has booked.
     */
    public function index(Request $request): Response
    {
        $consultations = Consultation::query()
            ->where(fn ($query) => $query
                ->where('user_id', $request->user()->id)
                // Bookings made before they registered, matched on email, so
                // they appear the moment the account exists.
                ->orWhere(fn ($q) => $q
                    ->whereNull('user_id')
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $request->user()->email)])))
            ->with('publishedReport')
            ->latest('id')
            ->get();

        return Inertia::render('Consultations/Index', [
            'consultations' => $consultations
                ->map(fn (Consultation $c): array => $this->cardPayload($c))
                ->all(),
        ]);
    }

    /**
     * Pay the quote.
     */
    public function pay(Request $request, Consultation $consultation): RedirectResponse
    {
        abort_unless($consultation->belongsToUser($request->user()), 403);

        $request->validate([
            'gateway' => ['required', 'string', Rule::in(array_keys(PaymentGatewayManager::GATEWAYS))],
        ]);

        try {
            $order = $this->checkout->begin($request->user(), $consultation);
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
     * Ask a question about the work.
     */
    public function followUp(Request $request, Consultation $consultation): RedirectResponse
    {
        abort_unless($this->maySee($request, $consultation), 403);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'photos' => ['array', 'max:4'],
            'photos.*' => ['image', 'max:4096'],
        ]);

        $paths = collect($request->file('photos') ?? [])
            ->map(fn ($file): string => $file->store('consultations/followups', 'public'))
            ->all();

        try {
            $this->consultations->followUp(
                $consultation,
                ConsultationFollowup::FROM_CLIENT,
                $validated['message'],
                $request->user(),
                $paths,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Sent. We will come back to you.'));
    }

    /**
     * The report as a PDF.
     *
     * A genuine download, unlike course material: they paid for this advice,
     * and a report they cannot show to a vet is worth much less than one they
     * can. Published reports only — a draft is not a report.
     */
    public function reportPdf(Request $request, Consultation $consultation): HttpResponse
    {
        abort_unless($this->maySee($request, $consultation), 403);

        $report = $consultation->publishedReport;

        abort_if($report === null, 404);

        return (new ConsultationReportDocument($report))->download();
    }

    /**
     * Who may open this consultation.
     *
     * Three ways in, and no others: the account it belongs to, an account whose
     * email matches an unclaimed booking, or the browser it was booked from.
     */
    private function maySee(Request $request, Consultation $consultation): bool
    {
        if ($consultation->belongsToUser($request->user())) {
            return true;
        }

        return in_array(
            $consultation->reference,
            (array) $request->session()->get(self::SESSION_KEY, []),
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Consultation $consultation): array
    {
        return [
            ...$this->cardPayload($consultation),
            'situation' => $consultation->situation,
            'category' => $consultation->category,
            'farm_type' => $consultation->farm_type,
            'animal_type' => $consultation->animal_type,
            'flock_size' => $consultation->flock_size,
            'state' => $consultation->state,
            'lga' => $consultation->lga,
            'attachments' => $consultation->attachmentUrls(),
            'quote_note' => $consultation->quote_note,
            'phone' => $consultation->phone,
            'email' => $consultation->email,
            'full_name' => $consultation->full_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPayload(Consultation $consultation): array
    {
        return [
            'reference' => $consultation->reference,
            'tier' => $consultation->tier->value,
            'tier_label' => $consultation->tier->label(),
            'is_urgent' => $consultation->tier->isUrgent(),
            'status' => $consultation->status->value,
            'status_label' => $consultation->status->label(),
            'tone' => $consultation->status->badgeTone(),
            'booked_at' => $consultation->created_at?->format('j M Y, H:i'),
            'response_due_at' => $consultation->response_due_at?->format('j M Y, H:i'),
            'response_countdown' => $consultation->responseCountdown(),
            'responded' => $consultation->first_responded_at !== null,
            'responded_at' => $consultation->first_responded_at?->format('j M Y, H:i'),
            'quote' => $consultation->quotedAmount(),
            'awaits_payment' => $consultation->awaitsPayment(),
            'paid' => $consultation->isPaid(),
            'has_report' => $consultation->publishedReport !== null,
            'url' => route('consultations.show', $consultation->reference),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reportPayload(Consultation $consultation): ?array
    {
        // publishedReport is scoped to published rows, so a draft simply is not
        // here — there is no flag on this page for the front end to get wrong.
        $report = $consultation->publishedReport;

        if ($report === null) {
            return null;
        }

        return [
            'title' => $report->title,
            'findings' => $report->findings,
            'recommendations' => $report->recommendations,
            'follow_up_actions' => $report->follow_up_actions,
            'attachments' => $report->attachmentUrls(),
            'published_at' => $report->published_at?->format('j F Y'),
            'pdf_url' => route('consultations.report', $consultation->reference),
        ];
    }

    /**
     * The kinds of problem people bring, as prompts rather than a taxonomy.
     *
     * Free text underneath, because the list will never cover everything and a
     * farmer who cannot find their problem on it should still be able to book.
     *
     * @return array<int, string>
     */
    private function categories(): array
    {
        return [
            __('Birds are dying'),
            __('Disease or vaccination'),
            __('Feed and nutrition'),
            __('Housing and ventilation'),
            __('Starting a new farm'),
            __('Records, costing and pricing'),
            __('Crops and soil'),
            __('Fish farming'),
            __('Livestock'),
            __('Something else'),
        ];
    }
}
