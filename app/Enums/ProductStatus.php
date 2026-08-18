<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case OutOfStock = 'out_of_stock';
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
            self::Draft => __('Draft'),
            self::PendingReview => __('Awaiting review'),
            self::Active => __('Live'),
            self::OutOfStock => __('Out of stock'),
            self::Rejected => __('Rejected'),
        };
    }

    /**
     * Whether a shopper can see this listing at all.
     */
    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::Active, self::OutOfStock], true);
    }

    /**
     * Whether it can actually be bought right now.
     */
    public function isBuyable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Counted towards the seller's approved-listing track record.
     */
    public function countsAsApproved(): bool
    {
        return in_array($this, [self::Active, self::OutOfStock], true);
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
