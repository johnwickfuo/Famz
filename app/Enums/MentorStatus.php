<?php

namespace App\Enums;

enum MentorStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Suspended = 'suspended';

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
            self::Pending => __('Waiting for approval'),
            self::Approved => __('Approved'),
            self::Suspended => __('Suspended'),
        };
    }

    /**
     * Whether this mentor may be matched, listed and hired.
     *
     * A suspended mentor keeps their profile and their history — suspension
     * stops new work, it does not erase the old.
     */
    public function isBookable(): bool
    {
        return $this === self::Approved;
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
