<?php

namespace App\Enums;

/**
 * What kind of work somebody is looking for.
 *
 * The distinction is real on a farm: a vaccination round is three days' work
 * and a farm manager is a year's, and a worker who only wants one of those
 * should not be shown the other. `Both` exists because most people looking for
 * farm work will take whatever is going, and a form that forces them to choose
 * would be lying about how the labour market here works.
 */
enum WorkTypeWanted: string
{
    case Permanent = 'permanent';
    case Temporary = 'temporary';
    case Both = 'both';

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
            self::Permanent => __('Permanent work'),
            self::Temporary => __('Casual or seasonal work'),
            self::Both => __('Either — whatever is available'),
        };
    }

    /**
     * Whether this worker would consider a listing of the given type.
     *
     * Contract counts as temporary from the worker's side: a fixed-term job is
     * not a permanent one however it is written up.
     */
    public function accepts(JobType $type): bool
    {
        return match ($this) {
            self::Both => true,
            self::Permanent => $type === JobType::Permanent,
            self::Temporary => $type !== JobType::Permanent,
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
