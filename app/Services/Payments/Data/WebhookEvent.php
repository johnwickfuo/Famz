<?php

namespace App\Services\Payments\Data;

/**
 * A webhook, parsed into the few things the application acts on.
 */
final class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $gateway,
        public readonly ?string $id,
        public readonly ?string $type,
        public readonly ?string $reference,
        public readonly bool $signatureValid,
        public readonly array $payload = [],
    ) {}

    /**
     * Whether this event is one that could mark an order paid. Everything else
     * is logged and ignored.
     */
    public function isSuccessfulCharge(): bool
    {
        return in_array($this->type, [
            'charge.success',
            'charge.completed',
        ], true);
    }
}
