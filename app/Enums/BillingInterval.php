<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum BillingInterval: string
{
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
            self::Weekly => __('Weekly'),
            self::Monthly => __('Monthly'),
        };
    }

    public function unitLabel(): string
    {
        return match ($this) {
            self::Weekly => __('week'),
            self::Monthly => __('month'),
        };
    }

    /**
     * The end of a period that began at `$from`.
     *
     * addMonthNoOverflow, so a term that starts on the 31st does not skip
     * February and bill somebody twice in March.
     */
    public function endOf(Carbon $from): Carbon
    {
        return match ($this) {
            self::Weekly => $from->copy()->addWeek(),
            self::Monthly => $from->copy()->addMonthNoOverflow(),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $interval): array => [$interval->value => $interval->label()])
            ->all();
    }
}
