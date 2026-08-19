<?php

namespace Database\Seeders;

use App\Models\Specialisation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The tags matching runs on.
 *
 * Not decoration and not a demo fixture — the matcher has nothing to rank
 * without these, so this seeder runs with the application rather than beside
 * it. The keywords are what a farmer would actually type: "my chicks are
 * dying", "how much feed per bird", "NAFDAC" — because the fallback matcher
 * reads them, and the fallback is what keeps a shortlist possible when the AI
 * layer is down.
 */
class SpecialisationSeeder extends Seeder
{
    /**
     * @var array<string, array<int, array{name: string, description: string, keywords: array<int, string>}>>
     */
    private const TAXONOMY = [
        'poultry' => [
            [
                'name' => 'Broiler production',
                'description' => 'Raising birds for meat: stocking, weight gain, feed conversion and when to sell.',
                'keywords' => ['broiler', 'meat bird', 'live weight', 'fcr', 'feed conversion', 'six weeks', 'table bird'],
            ],
            [
                'name' => 'Layer management',
                'description' => 'Egg production: point of lay, laying percentage, egg size and keeping a flock in lay.',
                'keywords' => ['layer', 'laying', 'eggs', 'point of lay', 'pullet', 'egg drop', 'crate of eggs'],
            ],
            [
                'name' => 'Brooding',
                'description' => 'The first weeks: heat, space, water and keeping day-old chicks alive.',
                'keywords' => ['brooding', 'day old', 'chicks', 'brooder', 'heat', 'chicks dying', 'mortality'],
            ],
            [
                'name' => 'Poultry health and vaccination',
                'description' => 'Disease, biosecurity and a vaccination programme that fits the flock.',
                'keywords' => ['newcastle', 'gumboro', 'coccidiosis', 'vaccination', 'disease', 'sick birds', 'biosecurity', 'antibiotics'],
            ],
            [
                'name' => 'Hatchery and incubation',
                'description' => 'Fertile eggs, setting, candling, hatch rate and running an incubator.',
                'keywords' => ['hatchery', 'incubator', 'incubation', 'hatch rate', 'fertile eggs', 'candling', 'setter'],
            ],
        ],
        'feed' => [
            [
                'name' => 'Feed formulation',
                'description' => 'Mixing your own rations from what is in the market, and costing it honestly.',
                'keywords' => ['feed', 'formulation', 'ration', 'maize', 'soya', 'premix', 'mixing feed', 'least cost'],
            ],
            [
                'name' => 'Feed quality and storage',
                'description' => 'Buying raw materials, testing what arrived, and storing it through the rains.',
                'keywords' => ['aflatoxin', 'mouldy', 'storage', 'raw materials', 'feed quality', 'weevils', 'spoilage'],
            ],
        ],
        'livestock' => [
            [
                'name' => 'Small ruminants',
                'description' => 'Goats and sheep: breeding, feeding, housing and fattening for the season.',
                'keywords' => ['goat', 'sheep', 'ram', 'ewe', 'small ruminant', 'fattening', 'sallah'],
            ],
            [
                'name' => 'Cattle and dairy',
                'description' => 'Beef and milk: herd management, feeding and animal health.',
                'keywords' => ['cattle', 'cow', 'dairy', 'milk', 'beef', 'herd', 'ranch'],
            ],
            [
                'name' => 'Fish farming',
                'description' => 'Catfish and tilapia: ponds, water quality, feeding and harvest.',
                'keywords' => ['fish', 'catfish', 'tilapia', 'pond', 'aquaculture', 'fingerlings', 'water quality'],
            ],
            [
                'name' => 'Piggery',
                'description' => 'Pigs: housing, feeding, farrowing and disease.',
                'keywords' => ['pig', 'piggery', 'swine', 'farrowing', 'sow', 'boar', 'african swine fever'],
            ],
            [
                'name' => 'Livestock health and veterinary care',
                'description' => 'Disease, treatment and preventive care across livestock.',
                'keywords' => ['vet', 'veterinary', 'treatment', 'deworming', 'livestock disease', 'drugs'],
            ],
        ],
        'crops' => [
            [
                'name' => 'Crop agronomy',
                'description' => 'Land preparation, spacing, weeding and getting a yield out of a field.',
                'keywords' => ['maize', 'rice', 'cassava', 'yam', 'planting', 'spacing', 'weeding', 'yield', 'agronomy'],
            ],
            [
                'name' => 'Soil health and fertiliser',
                'description' => 'Soil testing, fertiliser choice and rates, and organic matter.',
                'keywords' => ['soil', 'fertiliser', 'npk', 'urea', 'manure', 'soil test', 'ph', 'compost'],
            ],
            [
                'name' => 'Pest and disease control',
                'description' => 'Identifying what is eating the crop and what to do about it.',
                'keywords' => ['pest', 'armyworm', 'fungus', 'spray', 'herbicide', 'pesticide', 'blight', 'insects'],
            ],
            [
                'name' => 'Irrigation and water',
                'description' => 'Dry-season farming, boreholes, pumps and drip systems.',
                'keywords' => ['irrigation', 'water', 'borehole', 'pump', 'drip', 'dry season', 'fadama'],
            ],
            [
                'name' => 'Greenhouse and protected cropping',
                'description' => 'Growing under cover: structures, climate and high-value vegetables.',
                'keywords' => ['greenhouse', 'tomato', 'pepper', 'protected', 'shade net', 'hydroponics'],
            ],
        ],
        'operations' => [
            [
                'name' => 'Farm construction and housing',
                'description' => 'Siting and building pens, sheds and stores that suit the climate and the budget.',
                'keywords' => ['construction', 'pen', 'housing', 'shed', 'building', 'ventilation', 'deep litter', 'battery cage'],
            ],
            [
                'name' => 'Waste management',
                'description' => 'Manure, litter and effluent: handling it, and turning it into something worth money.',
                'keywords' => ['waste', 'manure', 'litter', 'compost', 'biogas', 'effluent', 'smell', 'flies'],
            ],
            [
                'name' => 'Farm equipment and mechanisation',
                'description' => 'Choosing, running and maintaining equipment without over-buying.',
                'keywords' => ['equipment', 'machine', 'tractor', 'generator', 'incubator', 'mill', 'maintenance'],
            ],
        ],
        'business' => [
            [
                'name' => 'Farm business and record keeping',
                'description' => 'Costing, books, and knowing whether the farm actually made money.',
                'keywords' => ['records', 'book keeping', 'accounting', 'profit', 'costing', 'cash flow', 'budget', 'business plan'],
            ],
            [
                'name' => 'Marketing and sales',
                'description' => 'Finding buyers, pricing, and selling without being squeezed by middlemen.',
                'keywords' => ['marketing', 'sales', 'buyers', 'pricing', 'offtaker', 'market', 'branding', 'customers'],
            ],
            [
                'name' => 'Farm start-up and planning',
                'description' => 'Starting from nothing: what to build first, what it costs, and what to skip.',
                'keywords' => ['start', 'startup', 'beginner', 'new farm', 'capital', 'feasibility', 'how to begin'],
            ],
            [
                'name' => 'Funding, grants and compliance',
                'description' => 'Loans, grants, registration and the paperwork a farm business needs.',
                'keywords' => ['loan', 'grant', 'funding', 'bank', 'cac', 'nafdac', 'registration', 'compliance', 'bva'],
            ],
        ],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::TAXONOMY as $sector => $tags) {
            foreach ($tags as $tag) {
                Specialisation::query()->updateOrCreate(
                    ['slug' => Str::slug($tag['name'])],
                    [
                        'name' => $tag['name'],
                        'sector' => $sector,
                        'description' => $tag['description'],
                        'keywords' => $tag['keywords'],
                        'sort_order' => $order++,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
