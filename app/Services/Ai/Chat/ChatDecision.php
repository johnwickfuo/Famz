<?php

namespace App\Services\Ai\Chat;

/**
 * Whether to answer, and how much may be spent doing it.
 *
 * Three outcomes rather than two, and the third is the one that matters.
 * "Blocked" is a person being told no. "Cache only" is the platform being told
 * no — the day's budget is gone, so a cached answer is free and a new one is
 * not. The person on the other end may never notice the difference, which is
 * the whole point of having the state.
 */
final class ChatDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $cacheOnly,
        public readonly ?string $reason,
        public readonly int $retryAfterSeconds,
    ) {}

    public static function allowed(): self
    {
        return new self(true, false, null, 0);
    }

    public static function cacheOnly(string $reason): self
    {
        return new self(true, true, $reason, 0);
    }

    public static function blocked(string $reason, int $retryAfterSeconds = 0): self
    {
        return new self(false, false, $reason, $retryAfterSeconds);
    }
}
