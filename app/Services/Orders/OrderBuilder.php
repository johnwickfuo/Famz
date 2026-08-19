<?php

namespace App\Services\Orders;

use App\Enums\DeliveryMethod;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartLine;
use App\Services\Cart\CartService;
use App\Services\Settings\SettingsService;
use App\Support\Commission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning a cart into an order.
 *
 * One order is one payment. Inside it, one sub-order per seller, because each
 * seller fulfils, is paid and can be refunded independently — and because the
 * commission split has to be recorded per seller at the rate in force when the
 * buyer paid.
 *
 * Every figure written here is an integer number of kobo, and the seller's
 * payout is derived by subtracting the commission rather than by a second
 * percentage calculation, so `commission + payout == subtotal` holds exactly.
 */
class OrderBuilder
{
    public function __construct(
        private readonly CartService $cart,
        private readonly DeliveryQuoter $delivery,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array{name: string, phone: string, address: string, state: string, lga: string, note?: string|null}  $address
     * @param  array<int, string>  $deliveryMethods  seller id => delivery method value
     */
    public function build(
        User $buyer,
        array $address,
        array $deliveryMethods,
        string $currency = 'NGN',
    ): Order {
        $groups = $this->cart->groupedBySeller($buyer);

        if ($groups->isEmpty()) {
            throw new RuntimeException(__('There is nothing in your cart.'));
        }

        // Snapshotted once for the whole order, so every seller on it is
        // treated identically even if an administrator changes the rate
        // mid-checkout.
        $commissionPercent = (float) $this->settings->float('marketplace_commission_percent', 0.0);

        return DB::transaction(function () use ($buyer, $address, $deliveryMethods, $currency, $groups, $commissionPercent): Order {
            $order = new Order([
                'user_id' => $buyer->getKey(),
                'currency' => strtoupper($currency),
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

            $subtotal = 0;
            $deliveryTotal = 0;

            foreach ($groups as $group) {
                $subOrder = $this->buildSubOrder(
                    $order,
                    $group,
                    $deliveryMethods,
                    $address['state'],
                    $commissionPercent,
                );

                $subtotal += $subOrder->subtotal_kobo;
                $deliveryTotal += $subOrder->delivery_fee_kobo;
            }

            $order->forceFill([
                'subtotal_kobo' => $subtotal,
                'delivery_total_kobo' => $deliveryTotal,
                'grand_total_kobo' => $subtotal + $deliveryTotal,
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * @param  array{seller: SellerProfile, lines: Collection<int, CartLine>, subtotal_kobo: int}  $group
     * @param  array<int, string>  $deliveryMethods
     */
    private function buildSubOrder(
        Order $order,
        array $group,
        array $deliveryMethods,
        string $state,
        float $commissionPercent,
    ): SubOrder {
        $seller = $group['seller'];

        $method = DeliveryMethod::tryFrom($deliveryMethods[$seller->getKey()] ?? '')
            ?? DeliveryMethod::BuyerPickup;

        if (! $this->delivery->supports($seller, $state, $method)) {
            throw new RuntimeException(__(':seller cannot deliver that way to :state.', [
                'seller' => $seller->business_name,
                'state' => $state,
            ]));
        }

        // Prices are re-resolved here, not taken from the cart: the cart's
        // figure is a quote from whenever the buyer added the item.
        $lines = $group['lines'];
        $subtotal = 0;
        $items = [];

        foreach ($lines as $line) {
            $unitPrice = $line->currentUnitPriceKobo() ?? $line->unitPriceKobo;
            $lineTotal = $unitPrice * $line->quantity;
            $subtotal += $lineTotal;

            $items[] = [
                'product_id' => $line->productId,
                'product_variant_id' => $line->variantId,
                // Snapshotted: a rename or a deletion must not change what this
                // receipt says.
                'product_name' => $line->product->name,
                'variant_name' => $line->variant?->name,
                'unit_of_measure' => $line->product->unit_of_measure->value,
                'unit_price_kobo' => $unitPrice,
                'quantity' => $line->quantity,
                'line_total_kobo' => $lineTotal,
            ];
        }

        $split = Commission::on($subtotal, $commissionPercent);

        $subOrder = new SubOrder([
            'order_id' => $order->getKey(),
            'seller_id' => $seller->getKey(),
            'subtotal_kobo' => $split->subtotalKobo,
            'commission_percent_snapshot' => $split->percent(),
            'commission_amount_kobo' => $split->commissionKobo,
            'seller_payout_amount_kobo' => $split->payoutKobo,
            'delivery_method' => $method,
            'delivery_fee_kobo' => $this->delivery->feeForMethod($seller, $state, $method),
        ]);

        $subOrder->save();
        $subOrder->items()->createMany($items);

        return $subOrder;
    }
}
