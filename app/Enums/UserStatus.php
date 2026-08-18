<?php

namespace App\Enums;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
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
            self::Pending => __('Pending'),
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }

    /**
     * Whether an account in this state may sign in and use the platform.
     */
    public function isUsable(): bool
    {
        return $this !== self::Suspended;
    }
}
