<?php

namespace App\Services\Ai\Retrieval;

/**
 * The answer to a feed question, with everything needed to state it honestly.
 *
 * Every figure here was computed in PHP from a seeded table. None of it came
 * from a model, and the `sources` list is what the assistant is required to
 * cite when it repeats any of it.
 */
final class FeedCalculation
{
    /**
     * @param  array<int, array<string, mixed>>  $weeks
     * @param  array<int, string>  $sources
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $reason,
        public readonly string $species,
        public readonly string $breed,
        public readonly string $productionType,
        public readonly int $birdCount,
        public readonly int $fromWeek,
        public readonly int $toWeek,
        public readonly float $totalFeedKg,
        public readonly float $totalWaterLitres,
        public readonly ?int $expectedWeightG,
        public readonly array $weeks,
        public readonly array $sources,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $weeks
     * @param  array<int, string>  $sources
     */
    public static function make(
        string $species,
        string $breed,
        string $productionType,
        int $birdCount,
        int $fromWeek,
        int $toWeek,
        float $totalFeedKg,
        float $totalWaterLitres,
        ?int $expectedWeightG,
        array $weeks,
        array $sources,
    ): self {
        return new self(
            true, null, $species, $breed, $productionType, $birdCount,
            $fromWeek, $toWeek, $totalFeedKg, $totalWaterLitres,
            $expectedWeightG, $weeks, $sources,
        );
    }

    /**
     * No table for this. The assistant must say so rather than estimate.
     */
    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, '', '', '', 0, 0, 0, 0.0, 0.0, null, [], []);
    }

    /**
     * How feed is actually bought here.
     *
     * Rounded up to a tenth: you cannot buy 0.42 of a bag, and a farmer told
     * "eleven bags" who actually needs 11.2 runs out on the last day.
     */
    public function bagsOf25Kg(): float
    {
        return ceil(($this->totalFeedKg / 25) * 10) / 10;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'reason' => $this->reason,
            'species' => $this->species,
            'breed' => $this->breed,
            'production_type' => $this->productionType,
            'bird_count' => $this->birdCount,
            'from_week' => $this->fromWeek,
            'to_week' => $this->toWeek,
            'total_feed_kg' => $this->totalFeedKg,
            'total_feed_25kg_bags' => $this->bagsOf25Kg(),
            'total_water_litres' => $this->totalWaterLitres,
            'expected_weight_g' => $this->expectedWeightG,
            'weeks' => $this->weeks,
            'sources' => $this->sources,
        ];
    }
}
