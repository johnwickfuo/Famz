<?php

namespace App\Services\Payments\Data;

/**
 * One bank a payout can be sent to.
 */
final class Bank
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
    ) {}
}
