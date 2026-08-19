<?php

namespace App\Enums;

/**
 * Where one offer in a haggle stands.
 *
 * `countered` is the state that makes the chain readable: an offer that has
 * been countered is not rejected, it is superseded, and the offer that
 * superseded it points back at it. Rewriting the old one would destroy the
 * record of what was actually asked for.
 */
enum OfferStatus: string
{
    /** Sent, awaiting an answer. */
    case Pending = 'pending';

    /** Answered with a different price. The reply is a new offer. */
    case Countered = 'countered';

    case Accepted = 'accepted';
    case Rejected = 'rejected';

    /** Nobody answered in time. */
    case Expired = 'expired';

    /** Taken back by whoever sent it. */
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
            self::Pending => __('Waiting for an answer'),
            self::Countered => __('Countered'),
            self::Accepted => __('Accepted'),
            self::Rejected => __('Turned down'),
            self::Expired => __('Ran out of time'),
            self::Withdrawn => __('Taken back'),
        };
    }

    /**
     * Whether this offer is still somebody's to answer.
     */
    public function isLive(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the haggle it belongs to is over.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected, self::Expired, self::Withdrawn], true);
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
