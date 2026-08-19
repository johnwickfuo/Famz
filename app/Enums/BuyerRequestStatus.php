<?php

namespace App\Enums;

/**
 * A wanted ad, from submission to closure.
 */
enum BuyerRequestStatus: string
{
    /** Submitted; an administrator has not looked at it yet. */
    case PendingApproval = 'pending_approval';

    /** Live on the board; sellers may offer. */
    case Open = 'open';

    /** The buyer has taken somebody's offer. */
    case OfferAccepted = 'offer_accepted';

    /** The buyer closed it themselves. */
    case Closed = 'closed';

    /** Nobody was found in time. */
    case Expired = 'expired';

    /** An administrator would not publish it. */
    case Rejected = 'rejected';

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
            self::PendingApproval => __('Waiting to be checked'),
            self::Open => __('Open for offers'),
            self::OfferAccepted => __('Offer accepted'),
            self::Closed => __('Closed'),
            self::Expired => __('Expired'),
            self::Rejected => __('Not published'),
        };
    }

    /**
     * Whether the public can see it at all.
     */
    public function isPublic(): bool
    {
        return in_array($this, [self::Open, self::OfferAccepted], true);
    }

    /**
     * Whether a seller can still offer on it.
     */
    public function acceptsOffers(): bool
    {
        return $this === self::Open;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::OfferAccepted, self::Closed, self::Expired, self::Rejected], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
