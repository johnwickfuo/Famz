<?php

namespace Database\Seeders;

use App\Models\WorkerSkill;
use Illuminate\Database\Seeder;

/**
 * What somebody can actually do on a farm.
 *
 * Written as jobs rather than as competencies. A poultry farmer looking for
 * help does not want "animal husbandry" — they want somebody who can brood
 * chicks without cooking them, or vaccinate two thousand birds in a morning.
 * These are the words used at the farm gate, which is the only place this list
 * has to work.
 *
 * Grouped by sector so the picker can group itself, and ordered so the most
 * commonly needed sit at the top of each group.
 */
class WorkerSkillSeeder extends Seeder
{
    /**
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    private const SKILLS = [
        'Poultry' => [
            ['Brooding', 'Managing day-old chicks: heat, spacing, water, the first two weeks.'],
            ['Vaccination and medication', 'Administering vaccines and treatments, and keeping to the programme.'],
            ['Feeding and watering', 'Rationing, filling, checking intake and spotting a bird that is off feed.'],
            ['Egg collection and handling', 'Collecting, grading, cleaning and crating without breakages.'],
            ['Litter management', 'Keeping bedding dry, turning it, and knowing when it has to come out.'],
            ['Cage and pen cleaning', 'Washing down, disinfecting and keeping a house fit to put birds in.'],
            ['Debeaking', 'Trimming beaks properly, at the right age.'],
        ],
        'Livestock' => [
            ['Cattle handling', 'Moving, restraining and working with cattle safely.'],
            ['Goat and sheep husbandry', 'Daily care, kidding and lambing, foot trimming.'],
            ['Pig husbandry', 'Farrowing, weaning, feeding and pen management.'],
            ['Milking', 'Hand or machine milking, and keeping the parlour clean.'],
            ['Animal health monitoring', 'Spotting a sick animal early and knowing who to call.'],
            ['Pasture and grazing management', 'Rotating, fencing and keeping grass ahead of the herd.'],
        ],
        'Fish' => [
            ['Pond management', 'Water quality, liming, aeration and stocking density.'],
            ['Fish feeding', 'Rationing by size and temperature, and not overfeeding a pond.'],
            ['Netting and harvesting', 'Seining, sorting and moving fish without losses.'],
        ],
        'Crops' => [
            ['Land preparation', 'Clearing, ridging, ploughing and bed making.'],
            ['Planting and transplanting', 'Spacing, depth and getting a stand to establish.'],
            ['Weeding', 'Manual and chemical weed control.'],
            ['Spraying', 'Mixing and applying agrochemicals safely and to the label.'],
            ['Irrigation', 'Running and maintaining pumps, lines and drip.'],
            ['Harvesting and post-harvest handling', 'Picking, drying, sorting and bagging.'],
            ['Greenhouse work', 'Working under cover: training, pruning, ventilation.'],
        ],
        'Across the farm' => [
            ['General farm labour', 'Whatever needs doing. Most farm work starts here.'],
            ['Feed mixing', 'Weighing and mixing rations to a formula.'],
            ['Record keeping', 'Mortality, feed, eggs, sales — written down daily and accurately.'],
            ['Farm security', 'Night watch, gate control and keeping stock and stores safe.'],
            ['Equipment repair', 'Fixing pumps, generators, feeders and fencing.'],
            ['Driving', 'Farm vehicles, deliveries and taking produce to market.'],
            ['Supervising workers', 'Running a gang, setting the day\'s work and checking it got done.'],
        ],
    ];

    public function run(): void
    {
        $sortOrder = 0;

        foreach (self::SKILLS as $sector => $skills) {
            foreach ($skills as [$name, $description]) {
                /*
                 * Matched on the name, not the slug: uniqueSlug() appends a
                 * suffix when one is taken, so using it as the lookup key would
                 * mint a fresh slug — and a duplicate row — on every run.
                 *
                 * firstOrCreate, not updateOrCreate: re-running must never
                 * stamp on wording an administrator has already adjusted.
                 */
                WorkerSkill::query()->firstOrCreate(
                    ['name' => $name],
                    [
                        'sector' => $sector,
                        'description' => $description,
                        'sort_order' => ++$sortOrder,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
