<?php

namespace App\Services\Payments\Data;

/**
 * What the bank says about an account number.
 *
 * The name here came from the bank, not from whoever typed the number. That
 * distinction is the whole value of resolution: it is how a seller who
 * fat-fingers a digit finds out before the money goes to a stranger.
 */
final class BankAccount
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly bool $resolved,
        public readonly ?string $accountName = null,
        public readonly ?string $accountNumber = null,
        public readonly ?string $bankCode = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    public static function failed(string $message, array $raw = []): self
    {
        return new self(resolved: false, message: $message, raw: $raw);
    }
}
