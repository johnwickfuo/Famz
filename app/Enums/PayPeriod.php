<?php

namespace App\Enums;

/**
 * How pay is quoted.
 *
 * Farm labour here is quoted daily far more often than monthly, and a board
 * that only understood salaries would be useless for most of the work on it.
 * Comparing a daily rate with a monthly one needs a common unit, which is what
 * `perMonthFactor` is for.
 */
enum PayPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

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
            self::Daily => __('a day'),
            self::Weekly => __('a week'),
            self::Monthly => __('a month'),
        };
    }

    public function noun(): string
    {
        return match ($this) {
            self::Daily => __('Daily'),
            self::Weekly => __('Weekly'),
            self::Monthly => __('Monthly'),
        };
    }

    /**
     * Roughly how many of these there are in a month.
     *
     * Deliberately rough. Twenty-six working days and four and a third weeks
     * are conventions, not facts, and this exists only so that a daily rate and
     * a monthly salary can be put on the same axis for matching — never to
     * quote anybody a figure.
     */
    public function perMonthFactor(): float
    {
        return match ($this) {
            self::Daily => 26.0,
            self::Weekly => 4.33,
            self::Monthly => 1.0,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->noun()])
            ->all();
    }
}
