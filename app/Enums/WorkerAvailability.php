<?php

namespace App\Enums;

enum WorkerAvailability: string
{
    case Immediately = 'immediately';
    case WithinTwoWeeks = 'within_two_weeks';
    case WithinAMonth = 'within_a_month';

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
            self::Immediately => __('Straight away'),
            self::WithinTwoWeeks => __('Within two weeks'),
            self::WithinAMonth => __('Within a month'),
        };
    }

    /**
     * Days of notice, for ranking somebody against a start date.
     */
    public function noticeDays(): int
    {
        return match ($this) {
            self::Immediately => 0,
            self::WithinTwoWeeks => 14,
            self::WithinAMonth => 30,
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Immediately => 'success',
            self::WithinTwoWeeks => 'info',
            self::WithinAMonth => 'muted',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
