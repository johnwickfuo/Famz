<?php

namespace App\Services\Payments\Data;

/**
 * A payout instruction: who to pay, how much, and where.
 */
final class TransferRequest
{
    public function __construct(
        public readonly int $amountKobo,
        public readonly string $currency,
        public readonly string $accountNumber,
        public readonly string $bankCode,
        public readonly string $accountName,
        public readonly string $reference,
        public readonly ?string $narration = null,
    ) {}
}
