<?php

namespace App\Enums;

enum DeliveryMethod: string
{
    /** The seller delivers and charges a fee they have set per state. */
    case SellerArranged = 'seller_arranged';

    /** The buyer collects; the seller's address is shown once payment clears. */
    case BuyerPickup = 'buyer_pickup';

    /**
     * Too big, too far or too awkward for a standard rate: the buyer asks, the
     * seller quotes, the buyer pays a top-up.
     *
     * The schema carries this from the start so no migration is needed later,
     * but it is only offered when the `delivery_quotes_enabled` feature flag is
     * on — half a negotiation flow is worse than none.
     */
    case QuoteRequired = 'quote_required';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::SellerArranged => __('Seller delivers'),
            self::BuyerPickup => __('I will collect it'),
            self::QuoteRequired => __('Ask the seller for a delivery price'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SellerArranged => __('The seller brings it to your address for a set fee.'),
            self::BuyerPickup => __('You collect from the seller. Their address is shown once you have paid.'),
            self::QuoteRequired => __('The seller works out a price for your address and you pay the difference.'),
        };
    }

    /**
     * Whether this method is switched on for the platform right now.
     */
    public function isAvailable(): bool
    {
        return $this !== self::QuoteRequired
            || (bool) settings('delivery_quotes_enabled', false);
    }

    /**
     * @return array<int, self>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $method): bool => $method->isAvailable(),
        ));
    }
}
