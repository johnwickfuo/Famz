<?php

namespace App\Services\Ai;

/**
 * What came back, or why nothing did.
 *
 * Every provider returns one of these and none of them throw. A farmer asking
 * how much feed a flock needs should get an honest "I cannot reach that right
 * now" rather than a stack trace, and the caller should not have to wrap every
 * call in a try block to make that true.
 */
final class AiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $text,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $latencyMs,
        public readonly ?string $error,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        string $text,
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $latencyMs = 0,
        array $meta = [],
    ): self {
        return new self(true, $text, $promptTokens, $completionTokens, $latencyMs, null, $meta);
    }

    public static function failure(string $error, int $latencyMs = 0): self
    {
        return new self(false, '', 0, 0, $latencyMs, $error);
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
