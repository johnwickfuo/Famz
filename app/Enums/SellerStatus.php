<?php

namespace App\Enums;

enum SellerStatus: string
{
    case Pending = 'pending';
    case NeedsMoreInfo = 'needs_more_info';
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
            self::Pending => __('Pending review'),
            self::NeedsMoreInfo => __('More information needed'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
        };
    }

    /**
     * Only an approved seller may list products. Everything else is a waiting
     * room.
     */
    public function canSell(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Whether the applicant can still edit and resubmit their application.
     */
    public function isOpenToApplicant(): bool
    {
        return in_array($this, [self::Pending, self::NeedsMoreInfo], true);
    }
}
