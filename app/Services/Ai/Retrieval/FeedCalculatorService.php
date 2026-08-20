<?php

namespace App\Services\Ai\Retrieval;

use App\Models\BreedStandard;
use App\Models\LivestockStandard;
use Illuminate\Support\Collection;

/**
 * Feed arithmetic, done in PHP.
 *
 * This class exists because of the one rule the assistant is built around: the
 * model does language and reasoning, and never numbers. A farmer asking how
 * much feed 500 Ross 308 need to week six is asking a question with an exact
 * answer, and a language model is the wrong tool for it — it will produce
 * something plausible, confidently, and be wrong by a bag or two. A wrong feed
 * figure is money out of somebody's pocket or birds off feed.
 *
 * So the sum happens here, deterministically, from seeded published tables. The
 * model is handed the result and its sources, and its job is to say it in
 * English or Pidgin.
 */
class FeedCalculatorService
{
    /**
     * Feed and water for a flock over a range of weeks.
     *
     * Returns `unavailable` rather than guessing when there is no table for the
     * breed or the weeks asked about. Saying "I do not have that" is a correct
     * answer; estimating it is not.
     */
    public function forFlock(
        string $breed,
        int $birdCount,
        int $fromWeek,
        int $toWeek,
        ?string $productionType = null,
    ): FeedCalculation {
        if ($birdCount < 1) {
            return FeedCalculation::unavailable(__('I need to know how many birds.'));
        }

        if ($fromWeek < 1 || $toWeek < $fromWeek) {
            return FeedCalculation::unavailable(__('I need a sensible week range.'));
        }

        $rows = $this->rowsFor($breed, $productionType);

        if ($rows->isEmpty()) {
            return FeedCalculation::unavailable(__('I do not have a feeding table for :breed.', ['breed' => $breed]));
        }

        $first = $rows->first();

        /*
         * Layer tables are quoted at intervals — weeks 16, 18, 20, 26 — so a
         * question about week 19 has to be answered from the nearest row at or
         * below it. That is how the printed guides are read too: you use the
         * last figure you were given until the next one.
         */
        $weeks = [];
        $totalFeedKg = 0.0;
        $totalWaterLitres = 0.0;
        $sources = [];
        $covered = 0;

        for ($week = $fromWeek; $week <= $toWeek; $week++) {
            $row = $this->rowForWeek($rows, $week);

            if ($row === null) {
                continue;
            }

            $covered++;

            $weekFeedKg = ($row->avg_feed_g_per_bird_per_day * 7 * $birdCount) / 1000;
            $weekWaterLitres = $weekFeedKg * $row->water_multiplier;

            $totalFeedKg += $weekFeedKg;
            $totalWaterLitres += $weekWaterLitres;

            $weeks[] = [
                'week' => $week,
                'g_per_bird_per_day' => $row->avg_feed_g_per_bird_per_day,
                'flock_kg_for_the_week' => round($weekFeedKg, 2),
                'water_litres_for_the_week' => round($weekWaterLitres, 1),
                'target_weight_g' => $row->target_weight_g,
                // The row a figure was read off is not always the week asked
                // about, and saying so is the difference between a citation and
                // a claim.
                'read_from_week' => $row->week_number,
            ];

            /*
             * Keyed on the publication, not the row. Citing "the Aviagen guide,
             * week 1; the Aviagen guide, week 2; ..." six times over is six
             * times the prompt budget for one fact, and it reads to the model
             * as six sources rather than one. Which row a figure was read off
             * already travels on the week itself, where it is actually useful.
             */
            $sources[$row->source ?: __('platform reference table')] = true;
        }

        if ($covered === 0) {
            return FeedCalculation::unavailable(__(
                'My table for :breed does not cover weeks :from to :to.',
                ['breed' => $first->breed, 'from' => $fromWeek, 'to' => $toWeek],
            ));
        }

        return FeedCalculation::make(
            species: $first->species,
            breed: $first->breed,
            productionType: $first->production_type,
            birdCount: $birdCount,
            fromWeek: $fromWeek,
            toWeek: $toWeek,
            totalFeedKg: round($totalFeedKg, 2),
            totalWaterLitres: round($totalWaterLitres, 1),
            expectedWeightG: $this->rowForWeek($rows, $toWeek)?->target_weight_g,
            weeks: $weeks,
            sources: array_keys($sources),
        );
    }

    /**
     * A daily figure for one week, which is what "how much do I feed them now"
     * actually asks.
     */
    public function forWeek(string $breed, int $birdCount, int $week, ?string $productionType = null): FeedCalculation
    {
        return $this->forFlock($breed, $birdCount, $week, $week, $productionType);
    }

    /**
     * The non-poultry equivalent.
     *
     * @return array<string, mixed>|null
     */
    public function forLivestock(string $species, ?string $category = null, ?float $weightKg = null): ?array
    {
        $query = LivestockStandard::query()
            ->active()
            ->whereRaw('LOWER(species) = ?', [mb_strtolower(trim($species))]);

        if ($category !== null) {
            $query->whereRaw('LOWER(category) LIKE ?', ['%'.mb_strtolower(trim($category)).'%']);
        }

        /*
         * When a weight is given and no category, pick the row closest to it.
         * Somebody with a 300 kg bull should get the growing-bull row, not
         * whichever cattle row happens to be first.
         */
        $standard = $weightKg !== null && $category === null
            ? $query->orderByRaw('ABS(COALESCE(typical_weight_kg, 0) - ?)', [$weightKg])->first()
            : $query->first();

        if ($standard === null) {
            return null;
        }

        $weight = $weightKg ?? $standard->typical_weight_kg;

        $feedKgPerDay = $standard->feed_kg_per_day;

        // Percentage-of-bodyweight rations only become a figure once there is a
        // weight to apply them to.
        if ($feedKgPerDay === null && $standard->feed_percent_of_bodyweight !== null && $weight !== null) {
            $feedKgPerDay = round($weight * $standard->feed_percent_of_bodyweight / 100, 3);
        }

        return [
            'species' => $standard->species,
            'category' => $standard->category,
            'assumed_weight_kg' => $weight,
            'feed_kg_per_day' => $feedKgPerDay,
            'feed_percent_of_bodyweight' => $standard->feed_percent_of_bodyweight,
            'water_litres_per_day' => $standard->water_litres_per_day,
            'notes' => $standard->notes,
            'source' => $standard->citation(),
        ];
    }

    /**
     * What a breed's table actually covers.
     *
     * The assembler needs this before it can decide what to do with a question
     * that names no weeks. "How much feed for 500 broilers" means the whole
     * cycle, and the whole cycle is whatever the table says it is — eight weeks
     * for a Ross, twelve for a Noiler. Hard-coding that here would put a figure
     * in two places and let them drift.
     *
     * @return array{species: string, breed: string, production_type: string, first_week: int, last_week: int}|null
     */
    public function coverageFor(string $breed, ?string $productionType = null): ?array
    {
        $rows = $this->rowsFor($breed, $productionType);

        if ($rows->isEmpty()) {
            return null;
        }

        $first = $rows->first();

        return [
            'species' => $first->species,
            'breed' => $first->breed,
            'production_type' => $first->production_type,
            'first_week' => (int) $rows->min('week_number'),
            'last_week' => (int) $rows->max('week_number'),
        ];
    }

    /**
     * Every breed the platform has a table for, for the picker and the prompt.
     *
     * @return array<int, array{breed: string, production_type: string, weeks: string}>
     */
    public function knownBreeds(): array
    {
        return BreedStandard::query()
            ->active()
            ->selectRaw('breed, production_type, MIN(week_number) as first_week, MAX(week_number) as last_week')
            ->groupBy('breed', 'production_type')
            ->orderBy('production_type')
            ->orderBy('breed')
            ->get()
            ->map(fn (BreedStandard $row): array => [
                'breed' => $row->breed,
                'production_type' => $row->production_type,
                'weeks' => $row->first_week.'–'.$row->last_week,
            ])
            ->all();
    }

    /**
     * @return Collection<int, BreedStandard>
     */
    private function rowsFor(string $breed, ?string $productionType): Collection
    {
        return BreedStandard::query()
            ->active()
            ->forBreed($breed)
            ->when($productionType, fn ($q) => $q->whereRaw('LOWER(production_type) = ?', [mb_strtolower($productionType)]))
            ->orderBy('week_number')
            ->get();
    }

    /**
     * The row that governs a given week: the latest one at or below it.
     *
     * @param  Collection<int, BreedStandard>  $rows
     */
    private function rowForWeek(Collection $rows, int $week): ?BreedStandard
    {
        return $rows->where('week_number', '<=', $week)->last();
    }
}
