<?php

namespace App\Services\Ai;

/**
 * The resolver used when no AI provider is configured.
 *
 * It always declines, which sends every caller down the keyword path. That is
 * the correct behaviour rather than a degraded one: the platform has to work
 * with no AI provider at all, and this class is what makes that the default
 * rather than an emergency.
 */
class NullTagResolver implements TagResolver
{
    public function suggest(string $need, array $vocabulary): TagSuggestion
    {
        return TagSuggestion::unavailable(__('No AI provider is configured.'));
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }
}
