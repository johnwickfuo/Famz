<?php

namespace App\Services\Ai\Chat;

/**
 * One answer, with everything needed to store it and to defend it later.
 *
 * The `context` is the point. Six months from now somebody will say the
 * assistant told them a bag of starter was eighteen thousand naira, and the
 * only useful reply is the exact block of figures the model was handed when it
 * wrote that sentence. So the block travels with the answer and gets stored on
 * the message rather than being thrown away once the prompt is built.
 */
final class ChatReply
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $text,
        public readonly string $intent,
        public readonly ContextBlock $context,
        public readonly bool $fromCache,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $latencyMs,
        public readonly bool $redirectedToConsultation,
        public readonly ?string $error,
    ) {}

    public static function fromProvider(
        string $text,
        string $intent,
        ContextBlock $context,
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $latencyMs,
    ): self {
        return new self(
            true, $text, $intent, $context, false, $provider, $model,
            $promptTokens, $completionTokens, $latencyMs, false, null,
        );
    }

    /**
     * An answer the platform wrote itself: a dosage redirect, an apology.
     *
     * No tokens, no provider, and `ok` is true — a deterministic refusal is a
     * correct answer, not a failure, and the difference matters because the
     * caller must not retry it or fall back from it.
     */
    public static function written(
        string $text,
        string $intent,
        ContextBlock $context,
        bool $redirectedToConsultation = false,
    ): self {
        return new self(
            true, $text, $intent, $context, false, null, null,
            0, 0, 0, $redirectedToConsultation, null,
        );
    }

    /**
     * A cached answer, replayed.
     */
    public static function cached(string $text, string $intent, ContextBlock $context): self
    {
        return new self(
            true, $text, $intent, $context, true, null, null,
            0, 0, 0, false, null,
        );
    }

    /**
     * The model could not be reached and there was nothing cached to fall back
     * on. The text is still a real, readable apology — never a stack trace and
     * never an empty string, because it goes straight onto somebody's screen.
     */
    public static function unavailable(string $text, string $intent, ContextBlock $context, string $error): self
    {
        return new self(
            false, $text, $intent, $context, false, null, null,
            0, 0, 0, false, $error,
        );
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
