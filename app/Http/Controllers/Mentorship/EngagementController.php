<?php

namespace App\Http\Controllers\Mentorship;

use App\Enums\DisputeReason;
use App\Http\Controllers\Controller;
use App\Models\MentorshipEngagement;
use App\Models\MentorshipInvoice;
use App\Models\MentorshipMatch;
use App\Models\MentorshipPackage;
use App\Services\Disputes\DisputeService;
use App\Services\Mentorship\EngagementService;
use App\Services\Mentorship\MentorshipCheckout;
use App\Services\Mentorship\ReviewService;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The client's side of an engagement.
 *
 * The contact details appear in exactly one place in this file — inside
 * `props()`, taken from `MentorProfile::contactFor($engagement)`, which decides
 * for itself whether this engagement has earned them. There is no branch here
 * that could get it wrong.
 */
class EngagementController extends Controller
{
    public function __construct(
        private readonly EngagementService $engagements,
        private readonly MentorshipCheckout $checkout,
        private readonly ReviewService $reviews,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * Everything this client has going.
     */
    public function index(Request $request): Response
    {
        $engagements = MentorshipEngagement::query()
            ->ownedBy($request->user())
            ->with(['mentor.user', 'invoices'])
            ->latest('id')
            ->get();

        return Inertia::render('Mentorship/Index', [
            'engagements' => $engagements->map(fn (MentorshipEngagement $e): array => [
                'reference' => $e->reference,
                'mentor' => $e->mentor?->displayName(),
                'mentor_slug' => $e->mentor?->slug,
                'package' => $e->package_title,
                'price' => $e->priceLabel(),
                'status' => $e->status->value,
                'status_label' => $e->status->label(),
                'tone' => $e->status->badgeTone(),
                'awaiting_you' => $e->isAwaitingConfirmation(),
                'started' => $e->started_at?->format('j M Y'),
                'url' => route('mentorship.show', $e),
            ])->all(),
        ]);
    }

    /**
     * Hire a mentor on a package: creates the engagement and its first invoice.
     */
    public function store(Request $request, MentorshipPackage $package): RedirectResponse
    {
        $validated = $request->validate([
            'brief' => ['nullable', 'string', 'max:2000'],
            'match_id' => ['nullable', 'integer', 'exists:mentorship_matches,id'],
        ]);

        try {
            $engagement = $this->engagements->request(
                $request->user(),
                $package,
                $validated['brief'] ?? '',
                isset($validated['match_id']) ? MentorshipMatch::find($validated['match_id']) : null,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('mentorship.show', $engagement)
            ->with('info', __('Almost there. Pay to start, and you will get their contact details straight away.'));
    }

    /**
     * The engagement dashboard.
     */
    public function show(Request $request, MentorshipEngagement $engagement): Response
    {
        abort_unless($engagement->client_id === $request->user()->id, 403);

        return Inertia::render('Mentorship/Show', $this->props($request, $engagement));
    }

    /**
     * Start paying for the next unpaid period.
     */
    public function pay(Request $request, MentorshipEngagement $engagement): RedirectResponse
    {
        abort_unless($engagement->client_id === $request->user()->id, 403);

        $request->validate([
            'gateway' => ['required', 'string', Rule::in(array_keys(PaymentGatewayManager::GATEWAYS))],
        ]);

        $invoice = $engagement->nextInvoice();

        if ($invoice === null) {
            return back()->with('info', __('There is nothing to pay on this engagement right now.'));
        }

        try {
            $order = $this->checkout->begin($request->user(), $invoice);
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
     * The client agrees the work is done, which is what releases the money.
     */
    public function confirm(Request $request, MentorshipEngagement $engagement): RedirectResponse
    {
        abort_unless($engagement->client_id === $request->user()->id, 403);

        try {
            $this->engagements->confirmComplete($engagement, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Confirmed. Your mentor has been paid — leave them a review if you like.'));
    }

    public function review(Request $request, MentorshipEngagement $engagement): RedirectResponse
    {
        abort_unless($engagement->client_id === $request->user()->id, 403);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->reviews->leave($engagement, $request->user(), (int) $validated['rating'], $validated['comment'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Thank you. It will appear on their profile once we have checked it.'));
    }

    /**
     * Raise a dispute, which routes into the same system as a marketplace one.
     */
    public function dispute(Request $request, MentorshipEngagement $engagement, DisputeService $disputes): RedirectResponse
    {
        $user = $request->user();

        $isParty = $engagement->client_id === $user->id || $engagement->mentor?->user_id === $user->id;

        abort_unless($isParty, 403);

        $validated = $request->validate([
            // Only the reasons that mean anything on an engagement. Offering
            // a client "arrived spoiled" would be absurd, and accepting it
            // would put nonsense in the admin's queue.
            'reason' => ['required', Rule::in(array_column(DisputeReason::mentorshipCases(), 'value'))],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $dispute = $disputes->raiseOnEngagement(
                $engagement,
                $user,
                DisputeReason::from($validated['reason']),
                $validated['description'],
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('disputes.show', $dispute)
            ->with('info', __('An administrator will look at this. The money stays where it is until they do.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Request $request, MentorshipEngagement $engagement): array
    {
        $engagement->load(['mentor.user', 'mentor.specialisations', 'invoices', 'review']);

        $mentor = $engagement->mentor;

        return [
            'engagement' => [
                'reference' => $engagement->reference,
                'package' => $engagement->package_title,
                'description' => $engagement->package_description,
                'brief' => $engagement->brief,
                'price' => $engagement->priceLabel(),
                'price_plain' => $engagement->price(),
                'is_periodic' => $engagement->isPeriodic(),
                'status' => $engagement->status->value,
                'status_label' => $engagement->status->label(),
                'tone' => $engagement->status->badgeTone(),
                'started' => $engagement->started_at?->format('j M Y'),
                'marked_complete' => $engagement->mentor_marked_complete_at?->format('j M Y'),
                'auto_confirm_at' => $engagement->auto_confirm_at?->format('j M Y'),
                'auto_confirm_in' => $engagement->auto_confirm_at?->diffForHumans(),
                'paid_to_date' => Money::fromKobo($engagement->paidToDateKobo()),
                'awaiting_confirmation' => $engagement->isAwaitingConfirmation(),
            ],
            'mentor' => $mentor?->publicCard(),
            /*
             * The reveal. `contactFor()` takes the engagement and decides for
             * itself; there is no flag passed from here, and before payment
             * this key is simply null rather than a hidden string.
             */
            'contact' => $mentor?->contactFor($engagement),
            'invoices' => $engagement->invoices->map(fn (MentorshipInvoice $invoice): array => [
                'reference' => $invoice->reference,
                'sequence' => $invoice->sequence,
                'period' => $invoice->periodLabel(),
                'amount' => $invoice->amount(),
                'status' => $invoice->status->value,
                'status_label' => $invoice->status->label(),
                'paid_at' => $invoice->paid_at?->format('j M Y'),
                'payable' => ! $invoice->isPaid() && $invoice->status->value === 'pending_payment',
            ])->all(),
            'gateways' => collect($this->gateways->availableFor($engagement->currency))
                ->map(fn ($gateway): array => ['key' => $gateway->key(), 'name' => $gateway->displayName()])
                ->values()->all(),
            'defaultGateway' => (string) settings('active_payment_gateway', 'paystack'),
            'review' => $engagement->review === null ? null : [
                'rating' => $engagement->review->rating,
                'comment' => $engagement->review->comment,
                'status' => $engagement->review->status->value,
                'status_label' => $engagement->review->status->label(),
            ],
            'mayReview' => $this->reviews->canReview($engagement, $request->user()),
            'reviewRefusal' => $this->reviews->refusalReason($engagement, $request->user()),
            'mayDispute' => app(DisputeService::class)->canRaiseOnEngagement($engagement, $request->user()),
            'disputeReasons' => collect(DisputeReason::mentorshipCases())
                ->map(fn (DisputeReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()])
                ->all(),
            'liveDispute' => ($live = app(DisputeService::class)->liveDisputeForEngagement($engagement)) === null
                ? null
                : ['url' => route('disputes.show', $live), 'status' => $live->status->label()],
        ];
    }
}
