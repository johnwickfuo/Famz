<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Released = 'released';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

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
            self::PendingPayment => __('Unpaid'),
            self::Paid => __('Paid, held'),
            self::Released => __('Paid out'),
            self::Cancelled => __('Cancelled'),
            self::Refunded => __('Refunded'),
        };
    }

    /**
     * Whether the money for this period has changed hands at all.
     */
    public function isPaid(): bool
    {
        return in_array($this, [self::Paid, self::Released], true);
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
