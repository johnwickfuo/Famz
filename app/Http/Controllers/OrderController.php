<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Enums\SubOrderStatus;
use App\Models\Order;
use App\Models\SubOrder;
use App\Services\Orders\FulfilmentService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OrderController extends Controller
{
    public function __construct(private readonly FulfilmentService $fulfilment) {}

    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['subOrders.seller', 'subOrders.items'])
            ->latest()
            ->paginate(15);

        return Inertia::render('Orders/Index', [
            'orders' => collect($orders->items())->map(fn (Order $order): array => [
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'grand_total' => Money::fromKobo($order->grand_total_kobo),
                'placed_at' => $order->created_at->format('j M Y'),
                'seller_count' => $order->subOrders->count(),
                'item_count' => $order->subOrders->sum(fn (SubOrder $sub): int => $sub->items->sum('quantity')),
            ])->all(),
            'pagination' => [
                'links' => $orders->linkCollection()->toArray(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        $order->load(['subOrders.seller', 'subOrders.items.product.images']);

        return Inertia::render('Orders/Show', [
            'order' => [
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'is_paid' => $order->isPaid(),
                'placed_at' => $order->created_at->format('j M Y, H:i'),
                'paid_at' => $order->paid_at?->format('j M Y, H:i'),
                'subtotal' => Money::fromKobo($order->subtotal_kobo),
                'delivery_total' => Money::fromKobo($order->delivery_total_kobo),
                'grand_total' => Money::fromKobo($order->grand_total_kobo),
                'address_lines' => $order->deliveryAddressLines(),
                'note' => $order->delivery_note,
            ],
            'subOrders' => $order->subOrders->map(fn (SubOrder $sub): array => [
                'reference' => $sub->reference,
                'status' => $sub->status->value,
                'status_label' => $sub->status->label(),
                'seller' => [
                    'name' => $sub->seller->business_name,
                    'slug' => $sub->seller->slug,
                    'location' => $sub->seller->location(),
                    // Only shown once the money is in and only for collection,
                    // which is the whole point of holding it back.
                    'address' => $this->pickupAddressFor($order, $sub),
                    'phone' => $order->isPaid() ? $sub->seller->phone : null,
                ],
                'delivery_method' => $sub->delivery_method->value,
                'delivery_method_label' => $sub->delivery_method->label(),
                'delivery_fee' => Money::fromKobo($sub->delivery_fee_kobo),
                'subtotal' => Money::fromKobo($sub->subtotal_kobo),
                'rejection_reason' => $sub->rejection_reason,
                'can_mark_received' => $this->canMarkReceived($sub),
                'items' => $sub->items->map(fn ($item): array => [
                    'name' => $item->label(),
                    'quantity' => $item->quantity,
                    'unit' => $item->unit_of_measure,
                    'unit_price' => Money::fromKobo($item->unit_price_kobo),
                    'line_total' => Money::fromKobo($item->line_total_kobo),
                    'image' => $item->imageUrl(),
                ])->all(),
                'timeline' => array_values(array_filter([
                    $sub->accepted_at ? ['label' => __('Accepted'), 'at' => $sub->accepted_at->format('j M, H:i')] : null,
                    $sub->shipped_at ? ['label' => __('On its way'), 'at' => $sub->shipped_at->format('j M, H:i')] : null,
                    $sub->delivered_at ? ['label' => __('Delivered'), 'at' => $sub->delivered_at->format('j M, H:i')] : null,
                    $sub->received_at ? ['label' => __('Confirmed by you'), 'at' => $sub->received_at->format('j M, H:i')] : null,
                    $sub->rejected_at ? ['label' => __('Rejected'), 'at' => $sub->rejected_at->format('j M, H:i')] : null,
                ])),
            ])->all(),
        ]);
    }

    /**
     * The buyer confirms they have the goods, which releases the seller's money
     * without waiting out the escrow window.
     */
    public function markReceived(Request $request, SubOrder $subOrder): RedirectResponse
    {
        abort_unless($subOrder->order->user_id === $request->user()->id, 403);

        try {
            $this->fulfilment->markReceived($subOrder, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Thank you — the seller has been paid.'));
    }

    /**
     * The seller's address, for collection, once payment has cleared.
     */
    private function pickupAddressFor(Order $order, SubOrder $sub): ?string
    {
        if (! $order->isPaid()) {
            return null;
        }

        return $sub->delivery_method === DeliveryMethod::BuyerPickup
            ? $sub->seller->address
            : null;
    }

    private function canMarkReceived(SubOrder $sub): bool
    {
        return $sub->order->isPaid()
            && in_array($sub->status, [
                SubOrderStatus::Accepted,
                SubOrderStatus::Shipped,
                SubOrderStatus::Delivered,
            ], true)
            && $sub->received_at === null;
    }
}
