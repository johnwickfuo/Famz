<?php

namespace App\Services\Ai;

/**
 * The provider used when none is configured.
 *
 * Not a test double — this ships. A platform installed without an API key
 * should have a chat page that explains itself rather than one that throws, and
 * the honest message is that the assistant has not been switched on yet.
 */
class NullAiProvider implements AiProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }

    public function model(): string
    {
        return 'none';
    }

    /**
     * @param  array<int, AiMessage>  $history
     */
    public function chat(string $systemPrompt, array $history, string $message): AiResponse
    {
        return AiResponse::failure(__('The assistant has not been set up on this platform yet.'));
    }
}
