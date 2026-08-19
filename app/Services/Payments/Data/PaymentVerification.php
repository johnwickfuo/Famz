<?php

namespace App\Services\Payments\Data;

/**
 * A gateway's answer to "was this actually paid, and for how much?".
 *
 * The amount comes back in kobo whatever units the gateway itself uses, so the
 * application never has to remember which is which.
 */
final class PaymentVerification
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly bool $successful,
        public readonly string $reference,
        public readonly int $amountKobo,
        public readonly string $currency,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $paidAt = null,
        public readonly ?string $status = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Whether this verification matches what the order actually asked for.
     *
     * Checked before anything is marked paid: a gateway that reports success
     * for the wrong amount or the wrong currency is not a payment for this
     * order, and treating it as one is how a platform gets robbed for the
     * difference.
     */
    public function matches(int $expectedKobo, string $expectedCurrency): bool
    {
        return $this->successful
            && $this->amountKobo === $expectedKobo
            && strtoupper($this->currency) === strtoupper($expectedCurrency);
    }
}
