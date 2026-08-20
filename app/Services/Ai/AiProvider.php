<?php

namespace App\Services\Ai;

/**
 * A model that can be asked something.
 *
 * Narrow on purpose. The platform's rules about what the assistant may say —
 * that every number comes from retrieved context, that dosages go to a
 * consultation, that the company name comes from BrandingService — live in
 * ChatService and in the system prompt, not here. A provider's only job is to
 * carry a prompt to a model and bring text back.
 *
 * Keeping it that narrow is what makes the provider swappable. Any of the
 * rules that matter would survive replacing Gemini with something else
 * tomorrow, because none of them are implemented in this layer.
 *
 * Implementations MUST NOT throw. Return AiResponse::failure() instead: the
 * assistant is free and open, and a provider being down must degrade to a
 * cached answer or an honest apology rather than a 500.
 */
interface AiProvider
{
    /**
     * @param  array<int, AiMessage>  $history  Oldest first, already windowed.
     */
    public function chat(string $systemPrompt, array $history, string $message): AiResponse;

    /**
     * Whether this provider is configured well enough to be worth calling.
     */
    public function isConfigured(): bool;

    public function name(): string;

    /**
     * The model actually being used, for the usage log.
     */
    public function model(): string;
}
