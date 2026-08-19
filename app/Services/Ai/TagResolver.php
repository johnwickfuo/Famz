<?php

namespace App\Services\Ai;

/**
 * Turning a farmer's own words into the tags the matcher runs on.
 *
 * Deliberately narrow. This is not "an AI service"; it answers exactly one
 * question, and it is allowed to answer "I could not", which is the whole point
 * — every caller has a working answer without it.
 */
interface TagResolver
{
    /**
     * @param  array<int, array{slug: string, name: string, description: string|null}>  $vocabulary
     *                                                                                               The tags that exist. The model may only choose from these.
     */
    public function suggest(string $need, array $vocabulary): TagSuggestion;

    /**
     * Whether this resolver is configured well enough to be worth calling.
     */
    public function isConfigured(): bool;

    public function name(): string;
}
