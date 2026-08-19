<?php

namespace App\Enums;

enum ReviewStatus: string
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
            self::Pending => __('Waiting for moderation'),
            self::Approved => __('Published'),
            self::Rejected => __('Not published'),
        };
    }

    /**
     * The only state in which a review is visible to anybody but its author,
     * the mentor and an administrator — and the only one that counts toward a
     * rating.
     */
    public function isPublic(): bool
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
