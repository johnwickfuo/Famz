<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The catalogue tree.
 *
 * Built to cover all of agriculture rather than poultry alone, so the platform
 * can grow into livestock, crops and services without a migration. Poultry is
 * the deepest branch because it is where this audience starts.
 *
 * Re-running is safe: nodes are matched on slug and updated in place, so an
 * administrator's edits to names and descriptions survive, and a category that
 * already has products attached is never recreated.
 */
class CategorySeeder extends Seeder
{
    /**
     * @var array<string, array{icon: string, description: string, children?: array<string, mixed>}>
     */
    private const TREE = [
        'Poultry' => [
            'icon' => 'heroicon-o-sparkles',
            'description' => 'Birds, eggs and everything a poultry house runs on.',
            'children' => [
                'Live birds' => [
                    'icon' => 'heroicon-o-heart',
                    'description' => 'Day-old chicks through to spent layers.',
                    'children' => [
                        'Day-old chicks' => ['description' => 'Broiler, layer and cockerel chicks, sold by the crate or the piece.'],
                        'Point-of-lay pullets' => ['description' => 'Pullets at 16 to 20 weeks, ready to start laying.'],
                        'Broilers' => ['description' => 'Meat birds, live weight.'],
                        'Layers' => ['description' => 'Laying hens in production.'],
                        'Cockerels' => ['description' => 'Cockerels and noiler birds.'],
                        'Turkeys' => ['description' => 'Poults and mature turkeys.'],
                        'Guinea fowl' => ['description' => 'Keets and mature guinea fowl.'],
                        'Ducks' => ['description' => 'Ducklings and mature ducks.'],
                        'Spent layers' => ['description' => 'End-of-lay hens sold for meat.'],
                    ],
                ],
                'Eggs' => [
                    'icon' => 'heroicon-o-circle-stack',
                    'description' => 'Table eggs and hatching eggs, by the crate.',
                    'children' => [
                        'Table eggs' => ['description' => 'Eggs for eating, sold by the crate.'],
                        'Fertile hatching eggs' => ['description' => 'Fertile eggs for incubation.'],
                    ],
                ],
                'Poultry equipment' => [
                    'icon' => 'heroicon-o-wrench-screwdriver',
                    'description' => 'The kit a poultry house cannot run without.',
                    'children' => [
                        'Feeders' => ['description' => 'Tube, trough and pan feeders in every size.'],
                        'Drinkers' => ['description' => 'Bell, nipple and plasson drinkers.'],
                        'Cages' => ['description' => 'Battery cages and colony systems.'],
                        'Incubators' => ['description' => 'Setters and hatchers, manual and automatic.'],
                        'Brooders' => ['description' => 'Gas, charcoal and infrared brooders.'],
                        'Egg trays and crates' => ['description' => 'Plastic and pulp trays, crates and fillers.'],
                        'Debeakers' => ['description' => 'Debeaking and beak-trimming equipment.'],
                    ],
                ],
                'Poultry housing' => [
                    'icon' => 'heroicon-o-home-modern',
                    'description' => 'Pens, netting and ventilation for a poultry house.',
                    'children' => [
                        'Pen construction' => ['description' => 'Poles, roofing and pen materials.'],
                        'Wire mesh and netting' => ['description' => 'Chicken wire, weld mesh and bird netting.'],
                        'Ventilation and fans' => ['description' => 'Extractors, fans and curtain systems.'],
                    ],
                ],
            ],
        ],

        'Livestock' => [
            'icon' => 'heroicon-o-globe-alt',
            'description' => 'Cattle, small ruminants, pigs, fish and more.',
            'children' => [
                'Cattle' => ['description' => 'Bulls, cows and calves.'],
                'Goats' => ['description' => 'Red Sokoto, West African Dwarf and cross breeds.'],
                'Sheep' => ['description' => 'Balami, Uda and Yankasa rams and ewes.'],
                'Pigs' => ['description' => 'Weaners, growers and breeding stock.'],
                'Rabbits' => ['description' => 'Breeding does, bucks and weaners.'],
                'Snails' => ['description' => 'Breeding snails and juveniles.'],
                'Fish and fingerlings' => ['description' => 'Catfish and tilapia fingerlings, juveniles and table fish.'],
                'Bees and honey' => ['description' => 'Colonies, hives and raw honey.'],
            ],
        ],

        'Crops & Seedlings' => [
            'icon' => 'heroicon-o-sun',
            'description' => 'Seed, seedlings, grain and produce.',
            'children' => [
                'Seeds' => ['description' => 'Certified and open-pollinated seed.'],
                'Seedlings and suckers' => ['description' => 'Nursery seedlings, suckers and cuttings.'],
                'Grains and cereals' => ['description' => 'Maize, sorghum, millet, rice and wheat.'],
                'Legumes' => ['description' => 'Cowpea, soybean and groundnut.'],
                'Tubers' => ['description' => 'Yam, cassava, potato and cocoyam.'],
                'Vegetables' => ['description' => 'Tomato, pepper, okra and leafy vegetables.'],
                'Fruits' => ['description' => 'Citrus, mango, pineapple, plantain and banana.'],
                'Tree crops' => ['description' => 'Cocoa, oil palm, cashew and rubber.'],
                'Fertiliser and soil' => ['description' => 'NPK, urea, organic manure and soil amendments.'],
            ],
        ],

        'Feed & Nutrition' => [
            'icon' => 'heroicon-o-beaker',
            'description' => 'Compounded feed, raw materials and supplements.',
            'children' => [
                'Poultry feed' => ['description' => 'Chick mash, growers mash, layers mash and broiler finisher.'],
                'Livestock feed' => ['description' => 'Cattle, small ruminant and pig rations.'],
                'Fish feed' => ['description' => 'Floating and sinking pellets by size.'],
                'Feed raw materials' => ['description' => 'Maize, soybean meal, GNC, wheat offal and bone meal.'],
                'Concentrates and premix' => ['description' => 'Protein concentrates and vitamin premixes.'],
                'Supplements and additives' => ['description' => 'Vitamins, amino acids, toxin binders and enzymes.'],
            ],
        ],

        'Veterinary & Drugs' => [
            'icon' => 'heroicon-o-shield-check',
            'description' => 'Medicines, vaccines and animal health supplies.',
            'children' => [
                'Vaccines' => ['description' => 'Newcastle, Gumboro, fowl pox and others. Cold chain applies.'],
                'Antibiotics' => ['description' => 'Prescription antibiotics for livestock and poultry.'],
                'Dewormers' => ['description' => 'Anthelmintics for poultry and livestock.'],
                'Disinfectants' => ['description' => 'Footbath, house and equipment disinfectants.'],
                'Vitamins and anti-stress' => ['description' => 'Multivitamins, electrolytes and anti-stress mixes.'],
                'Veterinary equipment' => ['description' => 'Syringes, automatic vaccinators and thermometers.'],
            ],
        ],

        'Equipment & Housing' => [
            'icon' => 'heroicon-o-building-storefront',
            'description' => 'Farm machinery, structures and power.',
            'children' => [
                'Tractors and implements' => ['description' => 'Tractors, ploughs, harrows and planters.'],
                'Irrigation' => ['description' => 'Pumps, sprinklers, drip lines and tanks.'],
                'Processing machines' => ['description' => 'Mills, threshers, graters, dryers and pelletisers.'],
                'Storage and silos' => ['description' => 'Silos, hermetic bags and storage cribs.'],
                'Greenhouses' => ['description' => 'Greenhouse structures and covering film.'],
                'Generators and solar' => ['description' => 'Generators, solar panels, inverters and batteries.'],
                'Cold chain' => ['description' => 'Freezers, cold rooms and cool boxes.'],
            ],
        ],

        'Tools' => [
            'icon' => 'heroicon-o-wrench',
            'description' => 'Hand tools, protective wear and consumables.',
            'children' => [
                'Hand tools' => ['description' => 'Cutlasses, hoes, rakes, shovels and diggers.'],
                'Sprayers' => ['description' => 'Knapsack, motorised and handheld sprayers.'],
                'Protective wear' => ['description' => 'Boots, coveralls, gloves, masks and goggles.'],
                'Weighing scales' => ['description' => 'Platform, hanging and bench scales.'],
                'Sacks and packaging' => ['description' => 'Woven sacks, crates, nets and twine.'],
            ],
        ],

        'Services' => [
            'icon' => 'heroicon-o-briefcase',
            'description' => 'People and services a farm hires rather than buys.',
            'children' => [
                'Veterinary services' => ['description' => 'Farm visits, vaccination rounds and diagnostics.'],
                'Transport and logistics' => ['description' => 'Livestock haulage, cold chain and general haulage.'],
                'Farm labour' => ['description' => 'Casual and contract farm labour.'],
                'Consultancy and training' => ['description' => 'Farm setup, feed formulation and business advice.'],
                'Equipment hire' => ['description' => 'Tractors, sprayers and processing equipment by the day.'],
                'Land preparation' => ['description' => 'Clearing, ploughing, harrowing and ridging.'],
                'Processing services' => ['description' => 'Milling, slaughter, dressing and packaging.'],
            ],
        ],
    ];

    public function run(): void
    {
        $this->plant(self::TREE, null);
    }

    /**
     * @param  array<string, mixed>  $nodes
     */
    private function plant(array $nodes, ?int $parentId): void
    {
        $sort = 0;

        foreach ($nodes as $name => $definition) {
            $sort += 10;

            $category = Category::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'parent_id' => $parentId,
                    'name' => $name,
                    'icon' => $definition['icon'] ?? null,
                    'description' => $definition['description'] ?? null,
                    'sort_order' => $sort,
                    'is_active' => true,
                ],
            );

            if (! empty($definition['children'])) {
                $this->plant($definition['children'], $category->getKey());
            }
        }
    }
}
