<?php

namespace App\Enums;

enum BillingType: string
{
    case OneTime = 'one_time';
    case Periodic = 'periodic';

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
            self::OneTime => __('One payment'),
            self::Periodic => __('Paid every period'),
        };
    }

    public function isPeriodic(): bool
    {
        return $this === self::Periodic;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
