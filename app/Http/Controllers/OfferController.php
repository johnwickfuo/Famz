<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\Product;
use App\Services\Offers\OfferService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Haggling over a listing, from the buyer's side.
 *
 * The seller's side is worked in the seller panel; there is no buyer-facing
 * accept, because on a listing only the seller can say yes.
 */
class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offers) {}

    public function store(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['required', 'numeric', 'min:1'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'unit_price' => __('your price'),
        ]);

        try {
            $this->offers->offerOnProduct(
                $product,
                $request->user(),
                (int) $validated['quantity'],
                Money::toKobo($validated['unit_price']),
                $validated['message'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Your offer is with the seller. We will tell you as soon as they answer.'));
    }

    /**
     * The buyer takes back an offer they have not had an answer to.
     */
    public function withdraw(Request $request, Offer $offer): RedirectResponse
    {
        Gate::authorize('withdraw', $offer);

        try {
            $this->offers->withdraw($offer, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Offer taken back.'));
    }

    /**
     * A buyer answers a seller's counter on a listing.
     */
    public function respond(Request $request, Offer $offer): RedirectResponse
    {
        Gate::authorize('respond', $offer);

        $validated = $request->validate([
            'decision' => ['required', 'in:accept,reject,counter'],
            'quantity' => ['required_if:decision,counter', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['required_if:decision,counter', 'numeric', 'min:1'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            match ($validated['decision']) {
                'accept' => $this->offers->accept($offer, $request->user()),
                'reject' => $this->offers->reject($offer, $request->user(), $validated['message'] ?? null),
                'counter' => $this->offers->counter(
                    $offer,
                    $request->user(),
                    (int) $validated['quantity'],
                    Money::toKobo($validated['unit_price']),
                    $validated['message'] ?? null,
                ),
            };
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', match ($validated['decision']) {
            'accept' => __('Agreed. Check your email for the link to pay.'),
            'reject' => __('Turned down.'),
            default => __('Your counter-offer is on its way.'),
        });
    }
}
