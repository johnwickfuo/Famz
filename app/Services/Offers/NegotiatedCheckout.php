<?php

namespace App\Services\Offers;

use App\Enums\DeliveryMethod;
use App\Models\NegotiatedPurchase;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Orders\DeliveryQuoter;
use App\Services\Settings\SettingsService;
use App\Support\Commission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning an agreed price into an order.
 *
 * This does not go through the cart. A negotiated purchase is one buyer, one
 * seller, one line, at a price nobody can change — putting it in the cart would
 * expose it to the cart's own repricing rules, which exist precisely to undo
 * stale prices, and would undo this one.
 *
 * Everything after the order exists is the ordinary Phase 3 path: the same
 * commission split, the same gateway, the same webhook, the same escrow. A
 * negotiated order is a normal order that happened to start with an argument.
 */
class NegotiatedCheckout
{
    public function __construct(
        private readonly DeliveryQuoter $delivery,
        private readonly SettingsService $settings,
    ) {}

    /**
     * What a purchase would cost, delivered a given way.
     *
     * @return array{subtotal_kobo: int, delivery_kobo: int, total_kobo: int}
     */
    public function quote(NegotiatedPurchase $purchase, string $state, DeliveryMethod $method): array
    {
        $subtotal = $purchase->totalKobo();
        $deliveryFee = $this->delivery->feeForMethod($purchase->seller, $state, $method);

        return [
            'subtotal_kobo' => $subtotal,
            'delivery_kobo' => $deliveryFee,
            'total_kobo' => $subtotal + $deliveryFee,
        ];
    }

    /**
     * @param  array{name: string, phone: string, address: string, state: string, lga: string, note?: string|null}  $address
     */
    public function build(
        NegotiatedPurchase $purchase,
        User $buyer,
        array $address,
        DeliveryMethod $method,
    ): Order {
        if ($purchase->buyer_id !== $buyer->getKey()) {
            throw new RuntimeException(__('This link is not yours.'));
        }

        if ($purchase->used_at !== null) {
            throw new RuntimeException(__('This offer has already been turned into an order.'));
        }

        if ($purchase->hasExpired()) {
            throw new RuntimeException(__('This price is no longer held. The offer window has closed.'));
        }

        $seller = $purchase->seller;

        if ($seller === null || ! $seller->isApproved()) {
            throw new RuntimeException(__('This seller is no longer trading.'));
        }

        if (! $this->delivery->supports($seller, $address['state'], $method)) {
            throw new RuntimeException(__(':seller cannot deliver that way to :state.', [
                'seller' => $seller->business_name,
                'state' => $address['state'],
            ]));
        }

        $product = $purchase->product;

        /*
         * Against `stock_quantity`, not against what is available.
         *
         * A reservation holds goods back from other buyers but does not remove
         * them from the shed, so `stock_quantity` is what the seller actually
         * has. Adding the reservation back to the available figure — the
         * obvious-looking version of this check — lets a seller who has since
         * sold the batch down to five still hand over ten.
         */
        if ($product !== null && $product->stock_quantity < $purchase->quantity) {
            throw new RuntimeException(__('The seller no longer has :count in stock.', [
                'count' => $purchase->quantity,
            ]));
        }

        return DB::transaction(function () use ($purchase, $buyer, $address, $method, $seller, $product): Order {
            $subtotal = $purchase->totalKobo();

            // The rate in force now, snapshotted onto the sub-order exactly as
            // an ordinary checkout would.
            $split = Commission::on(
                $subtotal,
                (float) $this->settings->float('marketplace_commission_percent', 0.0),
            );

            $order = new Order([
                'user_id' => $buyer->getKey(),
                'currency' => 'NGN',
                'subtotal_kobo' => 0,
                'delivery_total_kobo' => 0,
                'grand_total_kobo' => 0,
                'delivery_name' => $address['name'],
                'delivery_phone' => $address['phone'],
                'delivery_address' => $address['address'],
                'delivery_state' => $address['state'],
                'delivery_lga' => $address['lga'],
                'delivery_note' => $address['note'] ?? null,
            ]);

            $order->save();

            $deliveryFee = $this->delivery->feeForMethod($seller, $address['state'], $method);

            $subOrder = new SubOrder([
                'order_id' => $order->getKey(),
                'seller_id' => $seller->getKey(),
                'subtotal_kobo' => $split->subtotalKobo,
                'commission_percent_snapshot' => $split->percent(),
                'commission_amount_kobo' => $split->commissionKobo,
                'seller_payout_amount_kobo' => $split->payoutKobo,
                'delivery_method' => $method,
                'delivery_fee_kobo' => $deliveryFee,
            ]);

            $subOrder->save();

            $subOrder->items()->create($this->lineFor($purchase, $product));

            $order->forceFill([
                'subtotal_kobo' => $subtotal,
                'delivery_total_kobo' => $deliveryFee,
                'grand_total_kobo' => $subtotal + $deliveryFee,
            ])->save();

            /*
             * The reservation is spent, but the stock is NOT handed back: the
             * ordinary payment path decrements it when the money clears, and
             * releasing it here would put the goods back on sale while the
             * buyer was still on the payment page.
             */
            $this->consumeReservation($purchase, $order, $product);

            return $order->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function lineFor(NegotiatedPurchase $purchase, ?Product $product): array
    {
        // The offer itself is reachable from the purchase row, which carries
        // the order id, so nothing about the haggle needs copying onto the
        // line.
        $request = $purchase->buyerRequest;

        return [
            'product_id' => $product?->getKey(),
            'product_variant_id' => $purchase->product_variant_id,
            // Snapshotted, as everywhere: what this receipt says must not
            // change when a listing is renamed or a request is deleted.
            'product_name' => $product?->name
                ?? $request?->title
                ?? __('Agreed purchase'),
            'variant_name' => $purchase->variant?->name,
            'unit_of_measure' => $product?->unit_of_measure->value ?? $request?->unit ?? 'unit',
            'unit_price_kobo' => $purchase->unit_price_kobo,
            'quantity' => $purchase->quantity,
            'line_total_kobo' => $purchase->totalKobo(),
        ];
    }

    private function consumeReservation(NegotiatedPurchase $purchase, Order $order, ?Product $product): void
    {
        if ($product !== null && $purchase->reserved_quantity > 0) {
            $product->decrement('reserved_quantity', min($purchase->reserved_quantity, $product->reserved_quantity));
        }

        $purchase->forceFill([
            'used_at' => now(),
            'order_id' => $order->getKey(),
            'reserved_quantity' => 0,
        ])->save();
    }

    /**
     * Hand back stock nobody came for.
     *
     * Returns how many reservations were released, for the scheduled command.
     */
    public function releaseLapsedReservations(): int
    {
        $released = 0;

        NegotiatedPurchase::query()->dueToRelease()->with('product')->chunkById(100, function ($purchases) use (&$released): void {
            foreach ($purchases as $purchase) {
                DB::transaction(function () use ($purchase): void {
                    $product = $purchase->product;

                    if ($product !== null && $purchase->reserved_quantity > 0) {
                        $product->decrement(
                            'reserved_quantity',
                            min($purchase->reserved_quantity, $product->reserved_quantity),
                        );
                    }

                    $purchase->forceFill(['reserved_quantity' => 0])->save();
                });

                $released++;
            }
        });

        return $released;
    }
}
