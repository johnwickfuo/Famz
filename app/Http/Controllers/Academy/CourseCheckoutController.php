<?php

namespace App\Http\Controllers\Academy;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Academy\CourseCheckout;
use App\Services\Academy\EnrolmentService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Buying a course.
 *
 * "All sales final" is stated on the page, ticked by the buyer, and the wording
 * they ticked is stored with the enrolment — an argument six months later is
 * about what they were shown, not about what the page says by then.
 */
class CourseCheckoutController extends Controller
{
    public function __construct(
        private readonly CourseCheckout $checkout,
        private readonly EnrolmentService $enrolments,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function show(Request $request, Course $course): Response|RedirectResponse
    {
        abort_unless($course->isPurchasable(), 404);

        if ($this->enrolments->isEnrolled($request->user(), $course)) {
            return redirect()->route('academy.player', $course->slug);
        }

        $course->loadCount('modules');

        return Inertia::render('Academy/Checkout', [
            'course' => [
                'slug' => $course->slug,
                'title' => $course->title,
                'summary' => $course->summary,
                'cover' => $course->coverUrl(),
                'level' => $course->level->shortLabel(),
                'minutes' => $course->minutes(),
                'lesson_count' => $course->lessons()->count(),
                'price' => $course->price(),
                'price_kobo' => $course->price_kobo,
                'is_free' => $course->is_free || $course->price_kobo === 0,
            ],
            'terms' => $this->enrolments->termsText(),
            'gateways' => collect($this->gateways->availableFor($course->currency))
                ->map(fn ($gateway): array => ['key' => $gateway->key(), 'name' => $gateway->displayName()])
                ->values()->all(),
            'defaultGateway' => (string) settings('active_payment_gateway', 'paystack'),
        ]);
    }

    public function store(Request $request, Course $course): RedirectResponse
    {
        abort_unless($course->isPurchasable(), 404);

        $free = $course->is_free || $course->price_kobo === 0;

        $request->validate([
            // Not a checkbox that can be skipped: the whole point is that
            // nobody buys without having said they understood.
            'accept_terms' => ['accepted'],
            'gateway' => [
                Rule::requiredIf(! $free),
                'nullable',
                'string',
                Rule::in(array_keys(PaymentGatewayManager::GATEWAYS)),
            ],
        ], [
            'accept_terms.accepted' => __('Please confirm you understand that all sales are final.'),
        ]);

        if ($free) {
            try {
                $this->checkout->enrolFree($request->user(), $course, $request);
            } catch (RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }

            return redirect()
                ->route('academy.player', $course->slug)
                ->with('success', __('You are in. Start whenever you like.'));
        }

        try {
            $order = $this->checkout->begin($request->user(), $course, $request);
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

            // The order stands, unpaid and re-payable, exactly as an ordinary
            // checkout leaves it.
            return redirect()
                ->route('orders.show', $order)
                ->with('error', __('We could not start the payment. Please try again from your order.'));
        }

        $order->forceFill(['payment_gateway' => $gateway->key()])->save();

        return redirect()->away($initialisation->authorisationUrl);
    }
}
