<?php

namespace App\Services\Payments\Data;

/**
 * What a gateway gives back when a payment is started: somewhere to send the
 * buyer, and the reference the eventual webhook will quote.
 */
final class PaymentInitialisation
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $reference,
        public readonly string $authorisationUrl,
        public readonly ?string $gatewayReference = null,
        public readonly array $raw = [],
    ) {}
}
