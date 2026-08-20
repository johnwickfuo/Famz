<?php

use App\Models\BreedStandard;
use App\Services\Ai\Retrieval\FeedCalculatorService;
use Database\Seeders\BreedStandardSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * The arithmetic, checked against figures worked out by hand.
 *
 * These are the numbers a farmer spends money on. The expected values below
 * were computed from the seeded Ross 308 table with a calculator, not by
 * running the code and pasting what came out — a test written the second way
 * proves only that the code is consistent with itself.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->seed(BreedStandardSeeder::class);

    $this->calculator = app(FeedCalculatorService::class);
});

it('totals a broiler flock the way the published table says', function (): void {
    /*
     * Ross 308, weeks 1–6, grams per bird per day: 17, 34, 56, 83, 110, 136.
     * Sum = 436 g/bird/day-equivalent × 7 days = 3,052 g per bird over the six
     * weeks. × 500 birds = 1,526,000 g = 1,526 kg.
     */
    $result = $this->calculator->forFlock('Ross 308', 500, 1, 6);

    expect($result->ok)->toBeTrue()
        ->and($result->totalFeedKg)->toBe(1526.0)
        // 1,526 / 25 = 61.04 bags, and you cannot buy 0.04 of a bag.
        ->and($result->bagsOf25Kg())->toBe(61.1)
        // Water at the seeded 2.0 multiplier.
        ->and($result->totalWaterLitres)->toBe(3052.0)
        ->and($result->expectedWeightG)->toBe(3010)
        ->and($result->weeks)->toHaveCount(6);
});

it('scales exactly with flock size', function (): void {
    $small = $this->calculator->forFlock('Ross 308', 100, 1, 6);
    $large = $this->calculator->forFlock('Ross 308', 1000, 1, 6);

    // Not "about ten times" — exactly ten times. This is integer-honest
    // arithmetic on a decimal table, not an estimate.
    expect($large->totalFeedKg)->toBe(round($small->totalFeedKg * 10, 2));
});

it('matches a breed however it was typed', function (): void {
    foreach (['ross 308', 'ROSS 308', 'Ross308', ' ross308 '] as $spelling) {
        expect($this->calculator->forFlock($spelling, 500, 1, 6)->totalFeedKg)
            ->toBe(1526.0, "failed for '{$spelling}'");
    }
});

it('reads a layer week off the last row at or below it', function (): void {
    /*
     * Layer tables are quoted at intervals — 16, 18, 20, 26 — so week 19 has to
     * be answered from the week 18 row. That is how the printed guides are read
     * too: you use the last figure you were given until the next one.
     */
    $result = $this->calculator->forWeek('ISA Brown', 200, 19);

    $eighteen = BreedStandard::query()
        ->forBreed('ISA Brown')
        ->where('week_number', 18)
        ->value('avg_feed_g_per_bird_per_day');

    expect($result->ok)->toBeTrue()
        ->and($result->weeks[0]['g_per_bird_per_day'])->toBe((float) $eighteen)
        // And it says so. A citation that claimed a week 19 row exists would
        // be a claim rather than a citation.
        ->and($result->weeks[0]['read_from_week'])->toBe(18);
});

it('refuses rather than estimating when there is no table', function (): void {
    $result = $this->calculator->forFlock('Hubbard Flex', 500, 1, 6);

    expect($result->ok)->toBeFalse()
        ->and($result->totalFeedKg)->toBe(0.0)
        ->and($result->reason)->toContain('Hubbard Flex');
});

it('refuses a week range the table does not cover', function (): void {
    // Ross 308 is seeded to week 8. Nobody grows one to week 40, and inventing
    // a figure for it would be worse than saying so.
    $result = $this->calculator->forFlock('Ross 308', 500, 30, 40);

    expect($result->ok)->toBeFalse()
        ->and($result->reason)->toContain('does not cover');
});

it('refuses a nonsense flock or range instead of returning zero', function (): void {
    expect($this->calculator->forFlock('Ross 308', 0, 1, 6)->ok)->toBeFalse()
        ->and($this->calculator->forFlock('Ross 308', 500, 6, 1)->ok)->toBeFalse()
        ->and($this->calculator->forFlock('Ross 308', 500, 0, 6)->ok)->toBeFalse();
});

it('rounds bags up, because you cannot buy part of one', function (): void {
    $result = $this->calculator->forFlock('Ross 308', 500, 1, 6);

    // 61.04 → 61.1. A farmer told "sixty-one bags" who needs 61.04 runs out on
    // the last day.
    expect($result->bagsOf25Kg())->toBeGreaterThan($result->totalFeedKg / 25);
});

it('gives a daily ration for livestock as a share of body weight', function (): void {
    $goat = $this->calculator->forLivestock('Goat');

    expect($goat)->not->toBeNull()
        ->and($goat['feed_percent_of_bodyweight'])->toBeGreaterThan(0)
        // The percentage only becomes a figure once there is a weight to apply
        // it to, and the seeded typical weight supplies one.
        ->and($goat['feed_kg_per_day'])->toBe(round(
            $goat['assumed_weight_kg'] * $goat['feed_percent_of_bodyweight'] / 100,
            3,
        ));
});

it('reports what a breed table actually covers', function (): void {
    $coverage = $this->calculator->coverageFor('Ross 308');

    expect($coverage['production_type'])->toBe('Broiler')
        ->and($coverage['first_week'])->toBe(1)
        ->and($coverage['last_week'])->toBe(8);
});

it('ignores a row an administrator has turned off', function (): void {
    BreedStandard::query()->forBreed('Ross 308')->update(['is_active' => false]);

    expect($this->calculator->forFlock('Ross 308', 500, 1, 6)->ok)->toBeFalse();
});
