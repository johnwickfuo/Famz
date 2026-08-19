<?php

namespace App\Enums;

/**
 * Where an engagement has got to.
 *
 * The order matters commercially: contact details are revealed at Active and
 * never before, and the mentor's money moves from held to released only at
 * Completed. Everything between the two is the platform holding the ring.
 */
enum EngagementStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Disputed = 'disputed';

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
            self::PendingPayment => __('Waiting for payment'),
            self::Active => __('Under way'),
            self::AwaitingConfirmation => __('Waiting for you to confirm'),
            self::Completed => __('Finished'),
            self::Cancelled => __('Cancelled'),
            self::Refunded => __('Refunded'),
            self::Disputed => __('In dispute'),
        };
    }

    /**
     * The same state, said from the mentor's side.
     *
     * "Waiting for you to confirm" is true for the client and false for the
     * mentor, who is waiting on somebody else.
     */
    public function mentorLabel(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => __('Waiting for the client to confirm'),
            default => $this->label(),
        };
    }

    /**
     * Whether the two people may see each other's contact details.
     *
     * The single question the whole reveal turns on, asked in one place so a
     * new state cannot quietly become a way to see a phone number for free.
     */
    public function revealsContact(): bool
    {
        return in_array($this, [
            self::Active,
            self::AwaitingConfirmation,
            self::Completed,
            self::Disputed,
        ], true);
    }

    /**
     * Whether work is still going on.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Active, self::AwaitingConfirmation, self::Disputed], true);
    }

    /**
     * Whether this is over, however it ended.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Refunded], true);
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Active => 'active',
            self::AwaitingConfirmation, self::PendingPayment => 'pending',
            self::Disputed, self::Refunded => 'danger',
            self::Completed => 'active',
            self::Cancelled => 'neutral',
        };
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
