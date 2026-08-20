<?php

namespace App\Enums;

/**
 * How far an application has got.
 *
 * `hired` is the only case that carries any weight beyond reporting: it is what
 * unlocks the right to rate, and it is enforced in a policy rather than in the
 * interface. An ungated rating system on a board where nobody is verified would
 * be abused within a week.
 */
enum JobApplicationStatus: string
{
    case Applied = 'applied';
    case Viewed = 'viewed';
    case Shortlisted = 'shortlisted';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

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
            self::Applied => __('Applied'),
            self::Viewed => __('Seen'),
            self::Shortlisted => __('Shortlisted'),
            self::Hired => __('Hired'),
            self::Rejected => __('Not taken on'),
            self::Withdrawn => __('Withdrawn'),
        };
    }

    /**
     * What the worker is told, which is not always what the employer sees.
     *
     * "Seen" is a fact about the employer; "They have opened it" is what the
     * worker actually wants to know, and softening a rejection is not the same
     * as hiding it.
     */
    public function workerLabel(): string
    {
        return match ($this) {
            self::Applied => __('Sent'),
            self::Viewed => __('They have opened it'),
            self::Shortlisted => __('Shortlisted'),
            self::Hired => __('You got it'),
            self::Rejected => __('They went with somebody else'),
            self::Withdrawn => __('You withdrew'),
        };
    }

    /**
     * The one status that unlocks rating, in both directions.
     */
    public function isHired(): bool
    {
        return $this === self::Hired;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Hired, self::Rejected, self::Withdrawn], true);
    }

    /**
     * Statuses an employer may move an application to.
     *
     * Withdrawn is absent deliberately: only the worker withdraws, and an
     * employer who could do it for them would be rewriting somebody else's
     * decision.
     */
    public static function employerCases(): array
    {
        return [self::Viewed, self::Shortlisted, self::Hired, self::Rejected];
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Applied => 'info',
            self::Viewed => 'muted',
            self::Shortlisted => 'warning',
            self::Hired => 'success',
            self::Rejected, self::Withdrawn => 'muted',
        };
    }

    public function filamentColour(): string
    {
        return match ($this) {
            self::Applied => 'info',
            self::Viewed => 'gray',
            self::Shortlisted => 'warning',
            self::Hired => 'success',
            self::Rejected, self::Withdrawn => 'gray',
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
