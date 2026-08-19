<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Models\NegotiatedPurchase;
use App\Services\Offers\NegotiatedCheckout;
use App\Services\Orders\DeliveryQuoter;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use App\Support\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The private checkout an accepted offer earns.
 *
 * Reachable only by its token and only by the buyer it belongs to. The token
 * alone is not enough: a link forwarded to somebody else buys them nothing,
 * because the price was agreed with a person, not with a URL.
 */
class NegotiatedPurchaseController extends Controller
{
    public function __construct(
        private readonly NegotiatedCheckout $checkout,
        private readonly DeliveryQuoter $delivery,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function show(Request $request, NegotiatedPurchase $negotiatedPurchase): Response|RedirectResponse
    {
        abort_unless($negotiatedPurchase->buyer_id === $request->user()->id, 403);

        if ($negotiatedPurchase->order_id !== null) {
            return redirect()->route('orders.show', $negotiatedPurchase->order);
        }

        $negotiatedPurchase->load(['seller', 'product.images', 'buyerRequest', 'offer']);

        $profile = $request->user()->profileOrNew();
        $state = (string) ($request->input('state') ?? $profile->state ?? '');

        $seller = $negotiatedPurchase->seller;

        $methods = $state === ''
            ? [DeliveryMethod::BuyerPickup]
            : $this->delivery->methodsFor($seller, $state);

        return Inertia::render('Negotiated/Show', [
            'purchase' => [
                'token' => $negotiatedPurchase->token,
                'name' => $negotiatedPurchase->product?->name
                    ?? $negotiatedPurchase->buyerRequest?->title
                    ?? __('Agreed purchase'),
                'image' => $negotiatedPurchase->product?->images->first()?->url(),
                'quantity' => $negotiatedPurchase->quantity,
                'unit' => $negotiatedPurchase->product?->unit_of_measure->shortLabel()
                    ?? $negotiatedPurchase->buyerRequest?->unit,
                'unit_price' => Money::fromKobo($negotiatedPurchase->unit_price_kobo),
                'subtotal' => Money::fromKobo($negotiatedPurchase->totalKobo()),
                'subtotal_kobo' => $negotiatedPurchase->totalKobo(),
                // The listed price, so the buyer can see what they saved.
                'list_price' => $negotiatedPurchase->product === null
                    ? null
                    : Money::fromKobo($negotiatedPurchase->product->price_kobo),
                'saving' => $this->saving($negotiatedPurchase),
                'expires_at' => $negotiatedPurchase->expires_at->format('j M Y, H:i'),
                'expires_in' => $negotiatedPurchase->expires_at->diffForHumans(),
                'has_expired' => $negotiatedPurchase->hasExpired(),
                'seller' => [
                    'name' => $seller->business_name,
                    'slug' => $seller->slug,
                    'location' => $seller->location(),
                ],
            ],
            'address' => [
                'name' => $request->user()->displayName(),
                'phone' => $profile->phone,
                'address' => null,
                'state' => $state !== '' ? $state : $profile->state,
                'lga' => $profile->lga,
            ],
            'states' => Nigeria::states(),
            'deliveryMethods' => collect($methods)->map(fn (DeliveryMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'description' => $method->description(),
                'fee' => Money::fromKobo($this->delivery->feeForMethod($seller, $state, $method)),
                'fee_kobo' => $this->delivery->feeForMethod($seller, $state, $method),
            ])->all(),
            'gateways' => collect($this->gateways->availableFor('NGN'))
                ->map(fn ($gateway): array => ['key' => $gateway->key(), 'name' => $gateway->displayName()])
                ->values()->all(),
            'defaultGateway' => (string) settings('active_payment_gateway', 'paystack'),
        ]);
    }

    public function store(Request $request, NegotiatedPurchase $negotiatedPurchase): RedirectResponse
    {
        abort_unless($negotiatedPurchase->buyer_id === $request->user()->id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:400'],
            'state' => ['required', 'string', Rule::in(Nigeria::states())],
            'lga' => ['required', 'string', 'max:96'],
            'note' => ['nullable', 'string', 'max:500'],
            'delivery_method' => ['required', Rule::enum(DeliveryMethod::class)],
            'gateway' => ['required', 'string', Rule::in(array_keys(PaymentGatewayManager::GATEWAYS))],
        ]);

        try {
            $order = $this->checkout->build(
                $negotiatedPurchase,
                $request->user(),
                [
                    'name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'address' => $validated['address'],
                    'state' => $validated['state'],
                    'lga' => $validated['lga'],
                    'note' => $validated['note'] ?? null,
                ],
                DeliveryMethod::from($validated['delivery_method']),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $gateway = $this->gateways->gateway($validated['gateway']);

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

    private function saving(NegotiatedPurchase $purchase): ?string
    {
        $product = $purchase->product;

        if ($product === null) {
            return null;
        }

        $listed = $product->price_kobo * $purchase->quantity;
        $agreed = $purchase->totalKobo();

        return $listed > $agreed ? Money::fromKobo($listed - $agreed) : null;
    }
}
