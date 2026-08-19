<?php

namespace App\Enums;

/**
 * One seller's part of an order, from their acceptance through to the money
 * being settled to them.
 */
enum SubOrderStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';
    case Refunded = 'refunded';
    case Settled = 'settled';

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
            self::Pending => __('Awaiting the seller'),
            self::Accepted => __('Accepted'),
            self::Rejected => __('Rejected by the seller'),
            self::Shipped => __('On its way'),
            self::Delivered => __('Delivered'),
            self::Cancelled => __('Cancelled'),
            self::Disputed => __('In dispute'),
            self::Refunded => __('Refunded'),
            self::Settled => __('Settled'),
        };
    }

    /**
     * Whether the seller still has work to do on this.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Accepted, self::Shipped], true);
    }

    /**
     * Whether money for this part can still move to the seller. A dispute
     * stops the clock; a rejection or refund ends it.
     */
    public function canSettle(): bool
    {
        return in_array($this, [self::Delivered], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::Cancelled,
            self::Refunded,
            self::Settled,
        ], true);
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
