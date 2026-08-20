<?php

namespace App\Enums;

/**
 * What the employer is offering.
 *
 * Kept separate from WorkTypeWanted because the two are asked of different
 * people for different reasons, and collapsing them would make the matcher
 * compare a preference with an offer as though they were the same thing.
 */
enum JobType: string
{
    case Permanent = 'permanent';
    case Temporary = 'temporary';
    case Contract = 'contract';

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
            self::Permanent => __('Permanent'),
            self::Temporary => __('Casual or seasonal'),
            self::Contract => __('Fixed contract'),
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Permanent => __('An ongoing job with no end date.'),
            self::Temporary => __('A few days or weeks — a vaccination round, a harvest.'),
            self::Contract => __('A fixed term with an agreed end.'),
        };
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Permanent => 'success',
            self::Temporary => 'warning',
            self::Contract => 'info',
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
