<?php

namespace App\Enums;

/**
 * The roles the platform ships with. A user may hold any number of these at
 * once — they are additive capabilities, not a hierarchy.
 */
enum RoleName: string
{
    case Admin = 'admin';
    case Seller = 'seller';
    case Mentor = 'mentor';
    case Worker = 'worker';
    case Employer = 'employer';

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
            self::Admin => __('Administrator'),
            self::Seller => __('Seller'),
            self::Mentor => __('Mentor'),
            self::Worker => __('Farm worker'),
            self::Employer => __('Employer'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => __('Runs the platform: settings, branding, users and moderation.'),
            self::Seller => __('Lists feed, equipment and produce, and fulfils orders.'),
            self::Mentor => __('Answers consultations and delivers training.'),
            self::Worker => __('Looks for farm work and holds training certificates.'),
            self::Employer => __('Posts farm jobs and hires workers.'),
        };
    }
}
