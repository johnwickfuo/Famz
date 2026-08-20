<?php

namespace App\Services\Consultations;

use App\Enums\ConsultationTier;
use Illuminate\Support\Carbon;

/**
 * When the company has promised to come back.
 *
 * The standard tier counts plain elapsed hours: forty-eight hours means two
 * days, whatever day of the week it is.
 *
 * Urgent counts WORKING hours, and that distinction is the whole reason this
 * class exists. Six hours from a submission at nine at night is three in the
 * morning. If the queue treats that as the deadline, every overnight booking is
 * overdue before anybody has read it, the overdue flag stops meaning anything,
 * and the one signal the queue exists to give is the first thing lost.
 *
 * So the clock only runs when the office is open, and it opens at the start of
 * the next working day if the booking arrives outside those hours. The window
 * and the working days both come from settings, because a client who decides to
 * open on Sundays should not need a deploy.
 */
class ResponseClock
{
    public function startHour(): int
    {
        return $this->clampHour((int) settings('consultation_working_hours_start', 8), 8);
    }

    public function endHour(): int
    {
        $end = $this->clampHour((int) settings('consultation_working_hours_end', 17), 17);

        // An end before the start would make the working day negative and the
        // loop below never terminate.
        return $end > $this->startHour() ? $end : min(24, $this->startHour() + 1);
    }

    /**
     * ISO weekday numbers the office is open on: 1 is Monday, 7 is Sunday.
     *
     * @return array<int, int>
     */
    public function workingDays(): array
    {
        $days = collect(explode(',', (string) settings('consultation_working_days', '1,2,3,4,5,6')))
            ->map(fn (string $day): int => (int) trim($day))
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        // A configuration that closes every day would loop forever looking for
        // the next open one.
        return $days === [] ? [1, 2, 3, 4, 5, 6] : $days;
    }

    /**
     * The deadline for a booking made at `$from`.
     */
    public function dueAt(ConsultationTier $tier, ?Carbon $from = null): Carbon
    {
        $from = ($from ?? now())->copy();

        return $tier->countsWorkingHoursOnly()
            ? $this->addWorkingHours($from, $tier->hours())
            : $from->addHours($tier->hours());
    }

    /**
     * Walk `$hours` forward through open hours only.
     *
     * Deliberately a loop over whole hours rather than arithmetic on a total:
     * the number of hours is single digits, the working day can be any length,
     * and a closed-form version of this is the kind of clever that is wrong at
     * the boundaries.
     */
    public function addWorkingHours(Carbon $from, int $hours): Carbon
    {
        $cursor = $this->nextOpenMoment($from->copy());
        $remaining = max(0, $hours);

        while ($remaining > 0) {
            $closes = $cursor->copy()->setTime($this->endHour() % 24, 0);

            if ($this->endHour() === 24) {
                $closes = $cursor->copy()->startOfDay()->addDay();
            }

            $availableToday = $cursor->diffInMinutes($closes) / 60;

            if ($availableToday >= $remaining) {
                return $cursor->addMinutes((int) round($remaining * 60));
            }

            $remaining -= $availableToday;

            // Closed for the day: pick up when the office next opens.
            $cursor = $this->nextOpenMoment($closes->addSecond());
        }

        return $cursor;
    }

    /**
     * The first open moment at or after `$at`.
     */
    public function nextOpenMoment(Carbon $at): Carbon
    {
        $cursor = $at->copy();
        $days = $this->workingDays();

        // At most a week of days to try; the guard in workingDays() means one
        // of them is always open.
        for ($i = 0; $i <= 7; $i++) {
            if (in_array($cursor->dayOfWeekIso, $days, true)) {
                $opens = $cursor->copy()->setTime($this->startHour(), 0);
                $closes = $cursor->copy()->setTime($this->endHour() % 24, 0);

                if ($this->endHour() === 24) {
                    $closes = $cursor->copy()->startOfDay()->addDay();
                }

                if ($cursor->lt($opens)) {
                    return $opens;
                }

                if ($cursor->lt($closes)) {
                    return $cursor;
                }
            }

            // Tomorrow, from the moment it opens.
            $cursor = $cursor->addDay()->setTime($this->startHour(), 0);
        }

        return $cursor;
    }

    /**
     * A setting that is not a sane hour falls back rather than breaking.
     *
     * Somebody typing "9am" into a numeric field should not take the booking
     * form down; a bad value here is a configuration mistake, not an outage.
     */
    private function clampHour(int $hour, int $fallback): int
    {
        return $hour >= 0 && $hour <= 24 ? $hour : $fallback;
    }

    /**
     * How the promise is worded on the booking form.
     */
    public function promiseFor(ConsultationTier $tier): string
    {
        $hours = $tier->hours();

        return $tier->countsWorkingHoursOnly()
            ? trans_choice('Within one working hour|Within :count working hours', $hours, ['count' => $hours])
            : trans_choice('Within one hour|Within :count hours', $hours, ['count' => $hours]);
    }
}
