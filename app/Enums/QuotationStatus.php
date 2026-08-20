<?php

namespace App\Enums;

/**
 * The state of one version of a proposal.
 *
 * A quotation is never edited once it has gone out. Prices for cement, feed and
 * galvanised sheet move fast enough here that a document the client is holding
 * has to keep saying exactly what it said — so a revision is a new row with the
 * next version number, and the old one becomes `superseded` rather than being
 * overwritten.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Expired = 'expired';
    case Superseded = 'superseded';

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
            self::Draft => __('Draft'),
            self::Sent => __('Sent'),
            self::Expired => __('Lapsed'),
            self::Superseded => __('Replaced'),
        };
    }

    /**
     * Whether the client can see it at all.
     *
     * A draft is nothing to anybody outside the company. A lapsed or replaced
     * version stays visible, because a client comparing what changed between
     * revisions is doing something reasonable.
     */
    public function isVisibleToClient(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * The one version that can still be acted on.
     */
    public function isLive(): bool
    {
        return $this === self::Sent;
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Sent => 'success',
            self::Expired => 'warning',
            self::Superseded => 'muted',
        };
    }

    public function filamentColour(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'success',
            self::Expired => 'warning',
            self::Superseded => 'gray',
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
