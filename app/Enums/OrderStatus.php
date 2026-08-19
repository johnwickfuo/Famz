<?php

namespace App\Enums;

/**
 * The payment envelope's state. One order may hold several sub-orders, so this
 * describes the money, not the fulfilment — that lives on SubOrderStatus.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Completed = 'completed';
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
            self::PendingPayment => __('Awaiting payment'),
            self::Paid => __('Paid'),
            self::PartiallyFulfilled => __('Partly delivered'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
            self::Refunded => __('Refunded'),
        };
    }

    public function isPaid(): bool
    {
        return in_array($this, [
            self::Paid,
            self::PartiallyFulfilled,
            self::Completed,
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
