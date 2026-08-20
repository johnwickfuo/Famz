<?php

namespace App\Services\Ai\Retrieval;

use App\Models\MarketPriceSnapshot;
use App\Support\Money;

/**
 * What things cost, from this platform's own listings.
 *
 * Three rules, and the third is the one that matters:
 *
 *  1. Only this marketplace. Nothing scraped. A third-party price is stale the
 *     day after it is taken and unverifiable forever, and quoting one would
 *     mean the assistant citing a source it cannot stand behind.
 *
 *  2. Every figure carries a sample size and a capture date, because "18,500 a
 *     bag" and "18,500 a bag, from 23 listings, taken yesterday" are different
 *     claims and only the second is honest.
 *
 *  3. A thin sample widens to national, and a thin national sample is refused.
 *     A median built from two listings is not a market price, it is two
 *     people's asking prices wearing a statistic's clothes — and a farmer who
 *     budgets against it and then finds the real price is half again as much
 *     has been actively misled.
 */
class MarketPriceService
{
    /**
     * Below this many listings, a state figure is not worth quoting on its own.
     */
    public const DEFAULT_MINIMUM_SAMPLE = 5;

    /**
     * How stale a snapshot may be before it stops being usable at all.
     */
    public const DEFAULT_MAX_AGE_DAYS = 21;

    public function minimumSample(): int
    {
        return max(1, (int) settings('ai_price_minimum_sample', self::DEFAULT_MINIMUM_SAMPLE));
    }

    public function maxAgeDays(): int
    {
        return max(1, (int) settings('ai_price_max_age_days', self::DEFAULT_MAX_AGE_DAYS));
    }

    /**
     * Resolve a thing and a place to a price, or to an honest refusal.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $keywordGroup, ?string $state = null): array
    {
        $minimum = $this->minimumSample();

        $stateSnapshot = $state === null ? null : $this->latest($keywordGroup, $state);

        // The state figure, when there is enough behind it.
        if ($stateSnapshot !== null && $stateSnapshot->sample_size >= $minimum) {
            return $this->payload($stateSnapshot, widened: false);
        }

        $national = $this->latest($keywordGroup, null);

        /*
         * Widening is stated, never silent. A farmer who asked about Bayelsa
         * and is handed a national median deserves to know that is what
         * happened — otherwise they will plan against a number that does not
         * describe where they are.
         */
        if ($national !== null && $national->sample_size >= $minimum) {
            return $this->payload($national, widened: $state !== null, askedAbout: $state, thinState: $stateSnapshot);
        }

        // Still thin. Say so rather than quoting a weak figure.
        return [
            'ok' => false,
            'keyword_group' => $keywordGroup,
            'state' => $state,
            'reason' => $this->thinReason($keywordGroup, $state, $stateSnapshot, $national, $minimum),
            'sample_size' => $national?->sample_size ?? $stateSnapshot?->sample_size ?? 0,
        ];
    }

    /**
     * Everything the platform has a price for, for the prompt's vocabulary.
     *
     * @return array<int, string>
     */
    public function knownGroups(): array
    {
        return MarketPriceSnapshot::query()
            ->fresh($this->maxAgeDays())
            ->distinct()
            ->orderBy('keyword_group')
            ->pluck('keyword_group')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        MarketPriceSnapshot $snapshot,
        bool $widened,
        ?string $askedAbout = null,
        ?MarketPriceSnapshot $thinState = null,
    ): array {
        return [
            'ok' => true,
            'keyword_group' => $snapshot->keyword_group,
            'scope' => $snapshot->isNational() ? 'national' : $snapshot->state,
            'asked_about' => $askedAbout,
            'widened_to_national' => $widened,
            'median' => Money::fromKobo($snapshot->median_price_kobo),
            'median_kobo' => $snapshot->median_price_kobo,
            'range' => $snapshot->range(),
            'unit' => $snapshot->unit,
            'sample_size' => $snapshot->sample_size,
            'captured_at' => $snapshot->captured_at->format('j F Y'),
            'age_days' => $snapshot->ageInDays(),
            'source' => __('listings on this marketplace'),
            // Handed to the model so it can say why the answer is national when
            // a state was asked about.
            'note' => $widened
                ? __(
                    'Only :count listing(s) in :state, so this is the national figure.',
                    ['count' => $thinState?->sample_size ?? 0, 'state' => $askedAbout],
                )
                : null,
        ];
    }

    private function latest(string $keywordGroup, ?string $state): ?MarketPriceSnapshot
    {
        return MarketPriceSnapshot::query()
            ->where('keyword_group', $keywordGroup)
            ->when($state === null, fn ($q) => $q->whereNull('state'))
            ->when($state !== null, fn ($q) => $q->whereRaw('LOWER(state) = ?', [mb_strtolower(trim($state))]))
            ->fresh($this->maxAgeDays())
            ->latest('captured_at')
            ->first();
    }

    private function thinReason(
        string $group,
        ?string $state,
        ?MarketPriceSnapshot $stateSnapshot,
        ?MarketPriceSnapshot $national,
        int $minimum,
    ): string {
        if ($national === null && $stateSnapshot === null) {
            return __('Nobody on this marketplace is currently listing :thing, so I have no price for it.', [
                'thing' => $group,
            ]);
        }

        $best = $national?->sample_size ?? $stateSnapshot?->sample_size ?? 0;

        return __(
            'I only have :count listing(s) for :thing, which is too few to quote a price from — I need at least :minimum before the figure means anything.',
            ['count' => $best, 'thing' => $group, 'minimum' => $minimum],
        );
    }
}
