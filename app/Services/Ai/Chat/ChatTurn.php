<?php

namespace App\Services\Ai\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;

/**
 * What a controller gets back: either a stored exchange, or a refusal.
 */
final class ChatTurn
{
    private function __construct(
        public readonly bool $answered,
        public readonly ?ChatConversation $conversation,
        public readonly ?ChatMessage $question,
        public readonly ?ChatMessage $answer,
        public readonly ?ChatReply $reply,
        public readonly bool $degraded,
        public readonly ?string $blockedReason,
        public readonly int $retryAfterSeconds,
    ) {}

    public static function answered(
        ChatConversation $conversation,
        ChatMessage $question,
        ChatMessage $answer,
        ChatReply $reply,
        bool $degraded,
    ): self {
        return new self(true, $conversation, $question, $answer, $reply, $degraded, null, 0);
    }

    public static function blocked(string $reason, int $retryAfterSeconds): self
    {
        return new self(false, null, null, null, null, false, $reason, $retryAfterSeconds);
    }
}
