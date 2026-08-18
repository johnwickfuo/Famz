<?php

namespace App\Support;

/**
 * Naira formatting in one place.
 *
 * Money is stored in kobo as integers everywhere in this application; this is
 * the only place that turns it back into something a person reads.
 */
class Money
{
    public const SIGN = "\u{20A6}";

    public static function fromKobo(int $kobo, bool $withKobo = false): string
    {
        $naira = $kobo / 100;

        // Kobo are almost never quoted in this market — a bag of feed is
        // "₦18,500", not "₦18,500.00" — so they are shown only on request or
        // when the amount genuinely has them.
        $decimals = ($withKobo || $kobo % 100 !== 0) ? 2 : 0;

        return self::SIGN.number_format($naira, $decimals);
    }

    public static function toKobo(int|float|string $naira): int
    {
        return (int) round(((float) $naira) * 100);
    }

    /**
     * A compact form for tight spaces: ₦1.2m, ₦18.5k.
     */
    public static function compactFromKobo(int $kobo): string
    {
        $naira = $kobo / 100;

        return match (true) {
            $naira >= 1_000_000 => self::SIGN.rtrim(rtrim(number_format($naira / 1_000_000, 1), '0'), '.').'m',
            $naira >= 10_000 => self::SIGN.rtrim(rtrim(number_format($naira / 1_000, 1), '0'), '.').'k',
            default => self::fromKobo($kobo),
        };
    }
}
