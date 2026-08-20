<?php

namespace App\Enums;

/**
 * Whether a rating has been let through.
 *
 * Nothing is visible until an administrator approves it. That costs immediacy
 * and buys the ability to take down a rating written in a temper — on a board
 * where a bad word can cost somebody a season's work, that is a trade worth
 * making.
 */
enum RatingStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

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
            self::Pending => __('Waiting to be checked'),
            self::Approved => __('Published'),
            self::Rejected => __('Not published'),
        };
    }

    public function isPublished(): bool
    {
        return $this === self::Approved;
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'muted',
        };
    }

    public function filamentColour(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'gray',
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
