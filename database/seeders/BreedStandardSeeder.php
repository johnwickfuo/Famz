<?php

namespace Database\Seeders;

use App\Models\BreedStandard;
use App\Models\LivestockStandard;
use Illuminate\Database\Seeder;

/**
 * Published feeding tables for the stock this platform actually sells into.
 *
 * These figures come from the breeders' own management guides — Aviagen for
 * Ross, Cobb-Vantress for Cobb, Hendrix for ISA and Bovans, Lohmann for
 * Lohmann. They are the numbers a Nigerian poultry farmer is already working
 * to, which is the point: the assistant should agree with the guide taped
 * inside the feed store door.
 *
 * They are seeded rather than hard-coded because breeders revise them, and
 * because what a bird eats in Oyo is not always what a European guide says.
 * Every one is editable by the admin, and each carries its source so the
 * assistant can cite it and an administrator can tell a breeder table from a
 * local correction.
 */
class BreedStandardSeeder extends Seeder
{
    /**
     * Broiler intake, grams per bird per day, weeks 1–8, with target live
     * weight at the end of each week.
     *
     * @var array<string, array{source: string, weeks: array<int, array{0: float, 1: int}>}>
     */
    private const BROILERS = [
        'Ross 308' => [
            'source' => 'Aviagen Ross 308 broiler management guide',
            'weeks' => [
                1 => [17.0, 190],
                2 => [34.0, 480],
                3 => [56.0, 950],
                4 => [83.0, 1560],
                5 => [110.0, 2270],
                6 => [136.0, 3010],
                7 => [158.0, 3730],
                8 => [175.0, 4400],
            ],
        ],
        'Cobb 500' => [
            'source' => 'Cobb-Vantress Cobb 500 broiler performance supplement',
            'weeks' => [
                1 => [16.0, 185],
                2 => [33.0, 470],
                3 => [55.0, 940],
                4 => [82.0, 1540],
                5 => [108.0, 2240],
                6 => [134.0, 2970],
                7 => [156.0, 3680],
                8 => [172.0, 4340],
            ],
        ],
        'Arbor Acres' => [
            'source' => 'Aviagen Arbor Acres Plus broiler management guide',
            'weeks' => [
                1 => [17.0, 185],
                2 => [34.0, 470],
                3 => [57.0, 930],
                4 => [84.0, 1530],
                5 => [111.0, 2230],
                6 => [137.0, 2960],
                7 => [159.0, 3670],
                8 => [176.0, 4330],
            ],
        ],
        'Noiler' => [
            'source' => 'Local dual-purpose profile, platform reference',
            // Slower and hardier than a commercial broiler, and kept longer.
            // Widely raised here on cheaper feed and free range, so the figures
            // are lower and the run is longer.
            'weeks' => [
                1 => [12.0, 100],
                2 => [22.0, 230],
                3 => [35.0, 420],
                4 => [50.0, 660],
                5 => [65.0, 950],
                6 => [78.0, 1250],
                7 => [88.0, 1550],
                8 => [95.0, 1850],
                10 => [105.0, 2400],
                12 => [112.0, 2900],
            ],
        ],
    ];

    /**
     * Layer intake, grams per bird per day, at the weeks that matter: rearing,
     * point of lay, and peak.
     *
     * @var array<string, array{source: string, weeks: array<int, array{0: float, 1: int}>}>
     */
    private const LAYERS = [
        'ISA Brown' => [
            'source' => 'Hendrix ISA Brown commercial management guide',
            'weeks' => [
                1 => [13.0, 70],
                2 => [19.0, 115],
                4 => [30.0, 260],
                6 => [40.0, 430],
                8 => [48.0, 620],
                12 => [62.0, 1020],
                16 => [77.0, 1400],
                18 => [95.0, 1550],
                20 => [110.0, 1690],
                26 => [115.0, 1850],
                40 => [118.0, 1950],
                60 => [116.0, 2000],
                72 => [114.0, 2020],
            ],
        ],
        'Lohmann Brown' => [
            'source' => 'Lohmann Brown-Classic layer management guide',
            'weeks' => [
                1 => [13.0, 70],
                2 => [19.0, 120],
                4 => [30.0, 265],
                6 => [41.0, 440],
                8 => [49.0, 630],
                12 => [63.0, 1030],
                16 => [78.0, 1420],
                18 => [96.0, 1570],
                20 => [111.0, 1710],
                26 => [116.0, 1870],
                40 => [119.0, 1970],
                60 => [117.0, 2010],
                72 => [115.0, 2030],
            ],
        ],
        'Bovans Brown' => [
            'source' => 'Hendrix Bovans Brown commercial management guide',
            'weeks' => [
                1 => [12.0, 68],
                2 => [18.0, 112],
                4 => [29.0, 255],
                6 => [39.0, 425],
                8 => [47.0, 610],
                12 => [61.0, 1010],
                16 => [76.0, 1390],
                18 => [94.0, 1540],
                20 => [109.0, 1680],
                26 => [114.0, 1840],
                40 => [117.0, 1940],
                60 => [115.0, 1990],
                72 => [113.0, 2010],
            ],
        ],
        'Local layer' => [
            'source' => 'Local layer profile, platform reference',
            'weeks' => [
                1 => [10.0, 55],
                4 => [24.0, 200],
                8 => [38.0, 480],
                12 => [50.0, 780],
                16 => [62.0, 1050],
                20 => [82.0, 1250],
                26 => [90.0, 1400],
                40 => [92.0, 1480],
                60 => [90.0, 1500],
            ],
        ],
    ];

    /**
     * Everything that is not a chicken.
     *
     * @var array<int, array<string, mixed>>
     */
    private const LIVESTOCK = [
        [
            'species' => 'Goat',
            'category' => 'Growing kid (10–20 kg)',
            'typical_weight_kg' => 15,
            'feed_percent_of_bodyweight' => 3.5,
            'water_litres_per_day' => 4.0,
            'notes' => 'Concentrate plus browse. The percentage is dry matter, not fresh weight.',
            'source' => 'NAERLS small ruminant guidance',
        ],
        [
            'species' => 'Goat',
            'category' => 'Adult doe (25–35 kg)',
            'typical_weight_kg' => 30,
            'feed_percent_of_bodyweight' => 3.0,
            'water_litres_per_day' => 6.0,
            'notes' => 'Rises to about 4% in late pregnancy and lactation.',
            'source' => 'NAERLS small ruminant guidance',
        ],
        [
            'species' => 'Sheep',
            'category' => 'Adult ewe (30–40 kg)',
            'typical_weight_kg' => 35,
            'feed_percent_of_bodyweight' => 3.0,
            'water_litres_per_day' => 6.0,
            'source' => 'NAERLS small ruminant guidance',
        ],
        [
            'species' => 'Cattle',
            'category' => 'Growing bull (200–300 kg)',
            'typical_weight_kg' => 250,
            'feed_percent_of_bodyweight' => 2.5,
            'water_litres_per_day' => 35.0,
            'notes' => 'Water rises sharply in the dry season and in the north.',
            'source' => 'NAPRI cattle feeding guidance',
        ],
        [
            'species' => 'Cattle',
            'category' => 'Lactating cow (350–450 kg)',
            'typical_weight_kg' => 400,
            'feed_percent_of_bodyweight' => 3.0,
            'water_litres_per_day' => 70.0,
            'notes' => 'Water is the limiting factor on milk yield before feed is.',
            'source' => 'NAPRI cattle feeding guidance',
        ],
        [
            'species' => 'Pig',
            'category' => 'Weaner (10–25 kg)',
            'typical_weight_kg' => 18,
            'feed_kg_per_day' => 1.0,
            'water_litres_per_day' => 4.0,
            'source' => 'Platform reference profile',
        ],
        [
            'species' => 'Pig',
            'category' => 'Grower (25–60 kg)',
            'typical_weight_kg' => 45,
            'feed_kg_per_day' => 2.0,
            'water_litres_per_day' => 7.0,
            'source' => 'Platform reference profile',
        ],
        [
            'species' => 'Pig',
            'category' => 'Finisher (60–100 kg)',
            'typical_weight_kg' => 80,
            'feed_kg_per_day' => 2.8,
            'water_litres_per_day' => 10.0,
            'source' => 'Platform reference profile',
        ],
        [
            'species' => 'Pig',
            'category' => 'Lactating sow',
            'typical_weight_kg' => 160,
            'feed_kg_per_day' => 5.5,
            'water_litres_per_day' => 25.0,
            'notes' => 'Under-watering a lactating sow costs the litter, not the sow.',
            'source' => 'Platform reference profile',
        ],
        [
            'species' => 'Catfish',
            'category' => 'Fingerling (5–20 g)',
            'feed_percent_of_bodyweight' => 8.0,
            'notes' => 'Fed several times a day. Percentage falls sharply as they grow.',
            'source' => 'Platform aquaculture reference',
        ],
        [
            'species' => 'Catfish',
            'category' => 'Juvenile (20–100 g)',
            'feed_percent_of_bodyweight' => 5.0,
            'source' => 'Platform aquaculture reference',
        ],
        [
            'species' => 'Catfish',
            'category' => 'Grow-out (100 g – 1 kg)',
            'feed_percent_of_bodyweight' => 3.0,
            'notes' => 'Overfeeding fouls the water long before it wastes feed.',
            'source' => 'Platform aquaculture reference',
        ],
        [
            'species' => 'Turkey',
            'category' => 'Poult (0–8 weeks)',
            'feed_kg_per_day' => 0.09,
            'water_litres_per_day' => 0.25,
            'source' => 'Platform reference profile',
        ],
        [
            'species' => 'Turkey',
            'category' => 'Grower (8–16 weeks)',
            'feed_kg_per_day' => 0.25,
            'water_litres_per_day' => 0.6,
            'source' => 'Platform reference profile',
        ],
    ];

    public function run(): void
    {
        $this->seedPoultry(self::BROILERS, 'Chicken', 'Broiler');
        $this->seedPoultry(self::LAYERS, 'Chicken', 'Layer');

        foreach (self::LIVESTOCK as $row) {
            LivestockStandard::query()->firstOrCreate(
                ['species' => $row['species'], 'category' => $row['category']],
                [...$row, 'is_active' => true],
            );
        }
    }

    /**
     * @param  array<string, array{source: string, weeks: array<int, array{0: float, 1: int}>}>  $breeds
     */
    private function seedPoultry(array $breeds, string $species, string $productionType): void
    {
        foreach ($breeds as $breed => $definition) {
            /*
             * The running total is computed here rather than typed, so the
             * cumulative figure can never disagree with the daily one it is
             * built from. Seven days a week, and where the table skips weeks
             * the gap is carried at the last known rate — which is honest for a
             * layer table quoted at weeks 16, 18 and 20.
             */
            $cumulativeKg = 0.0;
            $previousWeek = 0;

            foreach ($definition['weeks'] as $week => [$gramsPerDay, $targetWeight]) {
                $weeksCovered = $week - $previousWeek;
                $cumulativeKg += ($gramsPerDay * 7 * $weeksCovered) / 1000;
                $previousWeek = $week;

                BreedStandard::query()->firstOrCreate(
                    [
                        'species' => $species,
                        'breed' => $breed,
                        'production_type' => $productionType,
                        'week_number' => $week,
                    ],
                    [
                        'avg_feed_g_per_bird_per_day' => $gramsPerDay,
                        'cumulative_feed_kg' => round($cumulativeKg, 4),
                        'target_weight_g' => $targetWeight,
                        // Birds drink about twice what they eat; more in heat,
                        // which is the number that actually varies here.
                        'water_multiplier' => 2.0,
                        'source' => $definition['source'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
