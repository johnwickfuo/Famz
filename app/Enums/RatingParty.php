<?php

namespace App\Enums;

/**
 * Which side of a hire wrote a rating.
 *
 * Stored explicitly rather than inferred from which user id happens to be
 * attached. An employer can also be a worker somewhere else on this platform,
 * and working out which hat somebody was wearing from their roles is exactly
 * the kind of guess that gets a rating attributed to the wrong party.
 */
enum RatingParty: string
{
    case Employer = 'employer';
    case Worker = 'worker';

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
            self::Employer => __('Employer'),
            self::Worker => __('Worker'),
        };
    }

    /**
     * Who this rating is about.
     */
    public function subject(): self
    {
        return $this === self::Employer ? self::Worker : self::Employer;
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
