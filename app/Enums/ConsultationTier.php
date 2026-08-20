<?php

namespace App\Enums;

/**
 * How quickly the company has promised to come back.
 *
 * The promise is the product. A farmer with birds dying this morning is buying
 * a response time, not an appointment slot, so the tier is one of only four
 * things the booking form insists on.
 */
enum ConsultationTier: string
{
    case Standard = 'standard';
    case Urgent = 'urgent';

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
            self::Standard => __('Standard'),
            self::Urgent => __('Urgent'),
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::Standard => __('Somebody will call you back within the standard window.'),
            self::Urgent => __('Jumps the queue. Costs more, and we ring you the same working day.'),
        };
    }

    /**
     * The settings key holding this tier's promised hours, so the promise on
     * the form and the deadline in the database can never drift apart.
     */
    public function hoursSettingKey(): string
    {
        return match ($this) {
            self::Standard => 'consultation_standard_response_hours',
            self::Urgent => 'consultation_urgent_response_hours',
        };
    }

    public function hours(): int
    {
        return max(1, (int) settings($this->hoursSettingKey(), $this === self::Urgent ? 6 : 48));
    }

    /**
     * Whether the clock only runs during working hours.
     *
     * Urgent is quoted in WORKING hours, which is the honest way to promise six
     * hours: a farmer who submits at nine at night is not owed a call at three
     * in the morning, and pretending otherwise makes every overnight submission
     * overdue before anybody has read it.
     */
    public function countsWorkingHoursOnly(): bool
    {
        return $this === self::Urgent;
    }

    public function isUrgent(): bool
    {
        return $this === self::Urgent;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $tier): array => [$tier->value => $tier->label()])
            ->all();
    }
}
