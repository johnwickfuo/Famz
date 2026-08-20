<?php

namespace App\Services\Ai\Chat;

use Illuminate\Support\Facades\Cache;

/**
 * The same twenty questions, answered once.
 *
 * A free assistant on a farming platform gets a very concentrated question
 * distribution. "How much feed for 500 broilers", "when do layers start
 * laying", "how much be day old chick" — these arrive dozens of times a day in
 * slightly different words, and paying a model to compose a fresh answer to
 * each one is paying for the same work repeatedly.
 *
 * The key is the normalised question AND a fingerprint of the retrieved
 * figures, which is the part that makes this safe rather than merely cheap. If
 * today's price snapshot differs from yesterday's, the fingerprint differs, the
 * key differs, and yesterday's answer cannot be replayed with a stale figure in
 * it. A cache keyed on the question alone would eventually tell somebody a
 * price that has not been true for a fortnight, which is exactly the failure
 * this whole feature is built to avoid.
 */
class AnswerCache
{
    public const DEFAULT_TTL_HOURS = 24;

    private function ttl(): int
    {
        return max(1, (int) settings('ai_answer_cache_hours', self::DEFAULT_TTL_HOURS)) * 3600;
    }

    public function get(string $normalisedQuestion, ContextBlock $context): ?string
    {
        $cached = Cache::get($this->key($normalisedQuestion, $context));

        return is_string($cached) && trim($cached) !== '' ? $cached : null;
    }

    public function put(string $normalisedQuestion, ContextBlock $context, string $answer): void
    {
        if (trim($normalisedQuestion) === '' || trim($answer) === '') {
            return;
        }

        Cache::put($this->key($normalisedQuestion, $context), $answer, $this->ttl());
    }

    /**
     * The cache key, and the reasoning is worth reading before changing it.
     *
     * The context is fingerprinted rather than stored in the key so the key
     * stays short, and it is fingerprinted over the RENDERED block rather than
     * over the figures alone — a change to a source attribution or a widening
     * note changes what the answer should say, even when every number stays
     * the same.
     */
    private function key(string $normalisedQuestion, ContextBlock $context): string
    {
        return 'ai:answer:'.sha1($normalisedQuestion."\n".$context->render());
    }
}
