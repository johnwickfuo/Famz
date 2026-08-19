<?php

namespace App\Enums;

/**
 * How money leaves the platform.
 */
enum PayoutMode: string
{
    /**
     * The seller asks, an administrator approves, and only then is a transfer
     * sent. Slower, and the right default while a platform is young: somebody
     * looks at every payout before it leaves.
     */
    case ManualRequest = 'manual_request';

    /**
     * A scheduled sweep pays every balance over the minimum on a set day of the
     * month. No seller has to ask, and nobody has to remember.
     */
    case ScheduledAuto = 'scheduled_auto';

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
            self::ManualRequest => __('Sellers request, an admin approves'),
            self::ScheduledAuto => __('Automatic on a set day each month'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ManualRequest => __('Nothing leaves the platform without somebody looking at it first.'),
            self::ScheduledAuto => __('Every balance above the minimum is swept on the payout day.'),
        };
    }

    /**
     * The mode in force right now.
     */
    public static function current(): self
    {
        return self::tryFrom((string) settings('payout_mode', self::ManualRequest->value))
            ?? self::ManualRequest;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->label()])
            ->all();
    }
}
