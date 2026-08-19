<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Cart\CartService;
use App\Services\Orders\CheckoutPricing;
use App\Services\Orders\DeliveryQuoter;
use App\Services\Orders\OrderBuilder;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use App\Support\Nigeria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly OrderBuilder $orders,
        private readonly DeliveryQuoter $delivery,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $lines = $this->cart->lines();

        if ($lines->isEmpty()) {
            return redirect()->route('cart.index')->with('info', __('Your cart is empty.'));
        }

        $profile = $request->user()->profileOrNew();

        // The delivery options and their fees depend on where the goods are
        // going, so the page reloads just `groups` when the buyer picks a
        // different state.
        $state = (string) ($request->input('state') ?? old('state', $profile->state ?? ''));

        $pricing = CheckoutPricing::forLines($lines);

        return Inertia::render('Checkout/Show', [
            'groups' => $this->groups($state),
            'subtotal' => Money::fromKobo($this->cart->subtotalKobo()),
            'subtotal_kobo' => $this->cart->subtotalKobo(),
            'address' => [
                'name' => $request->user()->displayName(),
                'phone' => $profile->phone,
                'address' => null,
                'state' => $state !== '' ? $state : $profile->state,
                'lga' => $profile->lga,
            ],
            'states' => Nigeria::states(),
            'gateways' => collect($this->gateways->availableFor('NGN'))
                ->map(fn ($gateway): array => [
                    'key' => $gateway->key(),
                    'name' => $gateway->displayName(),
                ])->values()->all(),
            'defaultGateway' => (string) settings('active_payment_gateway', 'paystack'),
            // The buyer is told about a moved price here, before they pay —
            // never for the first time on the receipt.
            'priceChanges' => collect($pricing->changes)
                ->map(fn (array $change): array => [
                    'name' => $change['name'],
                    'was' => Money::fromKobo($change['was_kobo']),
                    'now' => Money::fromKobo($change['now_kobo']),
                    'increased' => $change['now_kobo'] > $change['was_kobo'],
                ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:400'],
            'state' => ['required', 'string', Rule::in(Nigeria::states())],
            'lga' => ['required', 'string', 'max:96'],
            'note' => ['nullable', 'string', 'max:500'],
            'delivery_methods' => ['required', 'array'],
            'delivery_methods.*' => [Rule::enum(DeliveryMethod::class)],
            'gateway' => ['required', 'string', Rule::in(array_keys(PaymentGatewayManager::GATEWAYS))],
        ]);

        try {
            $order = $this->orders->build(
                $request->user(),
                [
                    'name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'address' => $validated['address'],
                    'state' => $validated['state'],
                    'lga' => $validated['lga'],
                    'note' => $validated['note'] ?? null,
                ],
                collect($validated['delivery_methods'])
                    ->mapWithKeys(fn ($method, $sellerId): array => [(int) $sellerId => $method])
                    ->all(),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $gateway = $this->gateways->gateway($validated['gateway']);

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

            // The order stays as it is: unpaid and re-payable, rather than
            // deleted out from under a buyer whose card may yet have been
            // charged.
            return redirect()
                ->route('orders.show', $order)
                ->with('error', __('We could not start the payment. Please try again from your order.'));
        }

        $order->forceFill(['payment_gateway' => $gateway->key()])->save();

        // The cart is emptied only once the buyer is on their way to pay.
        $this->cart->clear();

        return redirect()->away($initialisation->authorisationUrl);
    }

    /**
     * Try the payment again on an order that was never paid.
     *
     * A buyer whose bank timed out, or who closed the gateway page, has an
     * order sitting there unpaid. Without this they would have to rebuild the
     * whole cart — and the prices are already fixed on the order, so there is
     * nothing to re-quote.
     */
    public function pay(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        if ($order->isPaid()) {
            return redirect()->route('orders.show', $order);
        }

        if ($order->status !== OrderStatus::PendingPayment) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', __('This order can no longer be paid for.'));
        }

        $gateway = $this->gateways->gateway($order->payment_gateway);

        try {
            $initialisation = $gateway->initialise(
                $order,
                route('checkout.callback', ['reference' => $order->reference]),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __('We could not start the payment. Please try again shortly.'));
        }

        return redirect()->away($initialisation->authorisationUrl);
    }

    /**
     * Where the gateway sends the buyer back to.
     *
     * This deliberately changes nothing. Anybody can visit this URL, so it is
     * not evidence of a payment — the order moves on the webhook and only on
     * the webhook. This page just tells the buyer where things stand.
     */
    public function callback(Request $request): Response|RedirectResponse
    {
        $order = Order::query()
            ->where('reference', $request->string('reference'))
            ->where('user_id', $request->user()->id)
            ->first();

        if ($order === null) {
            return redirect()->route('orders.index')->with('error', __('We could not find that order.'));
        }

        return Inertia::render('Checkout/Callback', [
            'order' => [
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'is_paid' => $order->isPaid(),
                'grand_total' => Money::fromKobo($order->grand_total_kobo),
            ],
        ]);
    }

    /**
     * The current state of an order, for the callback page to poll while the
     * webhook lands.
     */
    public function status(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        return response()->json([
            'reference' => $order->reference,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'is_paid' => $order->isPaid(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groups(string $state): array
    {
        return $this->cart->groupedBySeller()
            ->map(function (array $group) use ($state): array {
                $seller = $group['seller'];

                $methods = $state === ''
                    ? [DeliveryMethod::BuyerPickup]
                    : $this->delivery->methodsFor($seller, $state);

                return [
                    'seller' => [
                        'id' => $seller->id,
                        'name' => $seller->business_name,
                        'location' => $seller->location(),
                    ],
                    'subtotal' => Money::fromKobo($group['subtotal_kobo']),
                    'subtotal_kobo' => $group['subtotal_kobo'],
                    'lines' => $group['lines']->map(fn ($line): array => [
                        'name' => $line->product->name,
                        'variant_name' => $line->variant?->name,
                        'quantity' => $line->quantity,
                        'unit' => $line->product->unit_of_measure->shortLabel(),
                        'line_total' => Money::fromKobo($line->lineTotalKobo()),
                        'is_live_animal' => $line->product->is_live_animal,
                        'is_perishable' => $line->product->is_perishable,
                    ])->all(),
                    'delivery_methods' => collect($methods)->map(fn (DeliveryMethod $method): array => [
                        'value' => $method->value,
                        'label' => $method->label(),
                        'description' => $method->description(),
                        'fee' => Money::fromKobo($this->delivery->feeForMethod($seller, $state, $method)),
                        'fee_kobo' => $this->delivery->feeForMethod($seller, $state, $method),
                    ])->all(),
                ];
            })
            ->all();
    }
}
