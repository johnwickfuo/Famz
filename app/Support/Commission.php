<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Splitting a sale between the seller and the platform, in whole kobo.
 *
 * Two rules keep this exact:
 *
 *  1. The percentage is converted to basis points and the split is done with
 *     integer arithmetic only. A float percentage of a kobo amount is where
 *     money quietly disappears — 7.5% of ₦18,500 is not representable in
 *     binary floating point, and the error compounds across a thousand orders.
 *
 *  2. The seller's payout is derived by *subtraction*, never by a second
 *     percentage calculation. That makes `commission + payout == subtotal` a
 *     structural guarantee rather than something that happens to be true for
 *     most inputs.
 */
final class Commission
{
    /**
     * The largest commission percentage that makes any sense. Guards against a
     * settings typo turning a 5% commission into 500%.
     */
    public const MAX_PERCENT = 100.0;

    private function __construct(
        public readonly int $subtotalKobo,
        public readonly int $basisPoints,
        public readonly int $commissionKobo,
        public readonly int $payoutKobo,
    ) {}

    /**
     * @param  int  $subtotalKobo  The seller's line total, before delivery.
     * @param  float|int|string  $percent  e.g. 5, 7.5, "2.25"
     */
    public static function on(int $subtotalKobo, float|int|string $percent): self
    {
        if ($subtotalKobo < 0) {
            throw new InvalidArgumentException('A subtotal cannot be negative.');
        }

        $basisPoints = self::toBasisPoints($percent);

        // Round half up on the half-kobo, computed entirely in integers.
        $commission = intdiv($subtotalKobo * $basisPoints + 5_000, 10_000);

        // Cannot exceed the subtotal even if somebody sets 100%.
        $commission = min($commission, $subtotalKobo);

        return new self(
            subtotalKobo: $subtotalKobo,
            basisPoints: $basisPoints,
            commissionKobo: $commission,
            payoutKobo: $subtotalKobo - $commission,
        );
    }

    /**
     * Basis points: 7.5% becomes 750. One hundredth of a percent is finer than
     * any commission anybody will set, and it makes the split exact.
     */
    public static function toBasisPoints(float|int|string $percent): int
    {
        $percent = (float) $percent;

        if ($percent < 0) {
            throw new InvalidArgumentException('A commission percentage cannot be negative.');
        }

        if ($percent > self::MAX_PERCENT) {
            throw new InvalidArgumentException(
                "A commission of {$percent}% is out of range; the maximum is ".self::MAX_PERCENT.'%.'
            );
        }

        return (int) round($percent * 100);
    }

    public function percent(): float
    {
        return $this->basisPoints / 100;
    }

    /**
     * The invariant this class exists to hold. Asserted in tests, and cheap
     * enough to be worth stating here as executable documentation.
     */
    public function balances(): bool
    {
        return $this->commissionKobo + $this->payoutKobo === $this->subtotalKobo;
    }
}
