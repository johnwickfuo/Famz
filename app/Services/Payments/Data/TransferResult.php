<?php

namespace App\Services\Payments\Data;

/**
 * The outcome of pushing money out to a seller's bank account.
 */
final class TransferResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $reference = null,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}
}
