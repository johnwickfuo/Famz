<?php

namespace App\Services\Ai;

/**
 * One turn in a conversation, as the provider layer sees it.
 *
 * Deliberately not the Eloquent model. What gets sent to a provider is a
 * trimmed, windowed subset of what is stored, and letting a database record
 * double as a wire format is how a `context_used` blob or a user id ends up in
 * somebody else's API logs.
 */
final class AiMessage
{
    public function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {}

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
