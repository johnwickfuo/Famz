<?php

namespace App\Enums;

/**
 * Where a job listing stands.
 *
 * `filled` and `closed` are kept apart on purpose. A listing that found
 * somebody and one the employer gave up on look identical to a database and
 * completely different to anybody deciding whether this board works.
 */
enum JobListingStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Filled = 'filled';
    case Closed = 'closed';
    case Expired = 'expired';

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
            self::Draft => __('Draft'),
            self::Open => __('Open'),
            self::Filled => __('Filled'),
            self::Closed => __('Closed'),
            self::Expired => __('Expired'),
        };
    }

    /**
     * Whether anybody outside the employer's own dashboard can see it.
     */
    public function isPublic(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Whether it is still taking applications.
     */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Filled, self::Closed, self::Expired], true);
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Open => 'success',
            self::Filled => 'info',
            self::Closed, self::Expired => 'muted',
        };
    }

    public function filamentColour(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Open => 'success',
            self::Filled => 'info',
            self::Closed, self::Expired => 'gray',
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
