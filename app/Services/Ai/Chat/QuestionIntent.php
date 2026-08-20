<?php

namespace App\Services\Ai\Chat;

/**
 * What the farmer is actually asking, worked out with regular expressions.
 *
 * Not a model call. Classifying "how much feed for 500 broilers to week 6"
 * needs to be fast, free and predictable, and a second round-trip to an API to
 * decide whether to make a first one is a cost with no benefit. It also means
 * the parse can be unit-tested, which a prompt cannot.
 *
 * It is deliberately generous about being wrong. When nothing matches, the
 * intent is `general` and the assistant answers from husbandry knowledge with
 * no figures at all — which is the safe failure, because the alternative is a
 * confidently invented number.
 */
final class QuestionIntent
{
    public const FEED = 'feed';

    public const PRICE = 'price';

    public const DOSAGE = 'dosage';

    public const GENERAL = 'general';

    /**
     * @param  array<string, mixed>  $slots
     */
    private function __construct(
        public readonly string $type,
        public readonly array $slots = [],
    ) {}

    /**
     * @param  array<string, mixed>  $slots
     */
    public static function of(string $type, array $slots = []): self
    {
        return new self($type, $slots);
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    public function slot(string $key, mixed $default = null): mixed
    {
        return $this->slots[$key] ?? $default;
    }
}
