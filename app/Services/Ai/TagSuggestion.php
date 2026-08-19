<?php

namespace App\Services\Ai;

/**
 * What the AI layer came back with, and whether it came back at all.
 *
 * A value object rather than a bare array so a caller cannot mistake "the model
 * said none of these apply" for "the model never answered". The first is a
 * result worth acting on; the second means falling back.
 */
final class TagSuggestion
{
    /**
     * @param  array<int, string>  $slugs  Specialisation slugs the model chose.
     */
    private function __construct(
        public readonly bool $answered,
        public readonly array $slugs,
        public readonly ?string $note = null,
    ) {}

    /**
     * @param  array<int, string>  $slugs
     */
    public static function of(array $slugs, ?string $note = null): self
    {
        return new self(true, array_values(array_unique(array_filter($slugs))), $note);
    }

    /**
     * Nobody answered: the key is missing, the call failed, the response was
     * unparseable, or it named nothing we recognise.
     */
    public static function unavailable(?string $why = null): self
    {
        return new self(false, [], $why);
    }

    public function isUsable(): bool
    {
        return $this->answered && $this->slugs !== [];
    }
}
