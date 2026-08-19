<?php

namespace App\Enums;

enum DisputeStatus: string
{
    /** Raised, nobody has looked at it. */
    case Open = 'open';

    /** An administrator is working on it. */
    case UnderReview = 'under_review';

    /** Decided for the buyer: refunded in full. */
    case ResolvedBuyer = 'resolved_buyer';

    /** Decided for the seller: the money is released to them. */
    case ResolvedSeller = 'resolved_seller';

    /** Split: part refunded, the rest released. */
    case ResolvedPartial = 'resolved_partial';

    /** Closed without a monetary decision — withdrawn, duplicate, abandoned. */
    case Closed = 'closed';

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
            self::Open => __('Open'),
            self::UnderReview => __('Being looked at'),
            self::ResolvedBuyer => __('Refunded to the buyer'),
            self::ResolvedSeller => __('Released to the seller'),
            self::ResolvedPartial => __('Partly refunded'),
            self::Closed => __('Closed'),
        };
    }

    /**
     * Whether the money is still frozen by this dispute.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Open, self::UnderReview], true);
    }

    public function isResolved(): bool
    {
        return ! $this->isLive();
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
