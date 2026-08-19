<?php

namespace App\Services\Orders;

use App\Enums\DeliveryMethod;
use App\Models\SellerDeliveryRate;
use App\Models\SellerProfile;

/**
 * What delivery costs, and which methods a seller offers.
 */
class DeliveryQuoter
{
    /**
     * The fee for this seller to deliver to this state, or null if they do not
     * deliver there.
     */
    public function feeKobo(SellerProfile $seller, string $state): ?int
    {
        $rate = SellerDeliveryRate::query()
            ->where('seller_id', $seller->getKey())
            ->where('state', $state)
            ->where('is_active', true)
            ->first();

        return $rate?->fee_kobo;
    }

    /**
     * The methods this seller can offer for this destination.
     *
     * Collection is always available — a buyer can always come to the shop.
     * Seller delivery only appears where the seller has set a rate for that
     * state, because an unpriced delivery is a promise nobody has costed.
     *
     * @return array<int, DeliveryMethod>
     */
    public function methodsFor(SellerProfile $seller, string $state): array
    {
        $methods = [DeliveryMethod::BuyerPickup];

        if ($this->feeKobo($seller, $state) !== null) {
            array_unshift($methods, DeliveryMethod::SellerArranged);
        }

        // Behind the feature flag until the quote conversation is built.
        if (DeliveryMethod::QuoteRequired->isAvailable()) {
            $methods[] = DeliveryMethod::QuoteRequired;
        }

        return $methods;
    }

    /**
     * The fee to charge for a chosen method.
     *
     * Only seller-arranged delivery costs anything up front: collection is
     * free by definition, and a quote is settled later as a top-up.
     */
    public function feeForMethod(SellerProfile $seller, string $state, DeliveryMethod $method): int
    {
        return match ($method) {
            DeliveryMethod::SellerArranged => $this->feeKobo($seller, $state) ?? 0,
            DeliveryMethod::BuyerPickup, DeliveryMethod::QuoteRequired => 0,
        };
    }

    /**
     * Whether a seller can be asked to fulfil by this method at all.
     */
    public function supports(SellerProfile $seller, string $state, DeliveryMethod $method): bool
    {
        return in_array($method, $this->methodsFor($seller, $state), true);
    }
}
