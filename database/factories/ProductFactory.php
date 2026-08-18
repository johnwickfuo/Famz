<?php

namespace Database\Factories;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\UnitOfMeasure;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Real listings from this market, with the prices and units they are
     * actually sold in. Kobo, so ₦18,500 is 1_850_000.
     *
     * @var array<int, array{name: string, unit: UnitOfMeasure, kobo: array{0: int, 1: int}, condition: ProductCondition, live?: bool, perishable?: bool}>
     */
    private const CATALOGUE = [
        ['name' => 'Broiler starter mash, 25kg', 'unit' => UnitOfMeasure::Bag, 'kobo' => [1_600_000, 2_400_000], 'condition' => ProductCondition::New],
        ['name' => 'Layers mash, 25kg', 'unit' => UnitOfMeasure::Bag, 'kobo' => [1_500_000, 2_200_000], 'condition' => ProductCondition::New],
        ['name' => 'Growers mash, 25kg', 'unit' => UnitOfMeasure::Bag, 'kobo' => [1_450_000, 2_100_000], 'condition' => ProductCondition::New],
        ['name' => 'Day-old broiler chicks', 'unit' => UnitOfMeasure::Bird, 'kobo' => [90_000, 160_000], 'condition' => ProductCondition::Live, 'live' => true],
        ['name' => 'Day-old layer chicks', 'unit' => UnitOfMeasure::Bird, 'kobo' => [95_000, 170_000], 'condition' => ProductCondition::Live, 'live' => true],
        ['name' => 'Point-of-lay pullets, 18 weeks', 'unit' => UnitOfMeasure::Bird, 'kobo' => [550_000, 850_000], 'condition' => ProductCondition::Live, 'live' => true],
        ['name' => 'Live broilers, 2.2kg average', 'unit' => UnitOfMeasure::Bird, 'kobo' => [700_000, 1_100_000], 'condition' => ProductCondition::Live, 'live' => true],
        ['name' => 'Table eggs, full crate', 'unit' => UnitOfMeasure::Crate, 'kobo' => [450_000, 700_000], 'condition' => ProductCondition::Fresh, 'perishable' => true],
        ['name' => 'Plasson automatic drinker', 'unit' => UnitOfMeasure::Piece, 'kobo' => [450_000, 900_000], 'condition' => ProductCondition::New],
        ['name' => 'Tube feeder, 15kg capacity', 'unit' => UnitOfMeasure::Piece, 'kobo' => [280_000, 550_000], 'condition' => ProductCondition::New],
        ['name' => 'Battery cage, 96 birds', 'unit' => UnitOfMeasure::Set, 'kobo' => [28_000_000, 52_000_000], 'condition' => ProductCondition::New],
        ['name' => 'Egg incubator, 528 eggs', 'unit' => UnitOfMeasure::Piece, 'kobo' => [22_000_000, 45_000_000], 'condition' => ProductCondition::New],
        ['name' => 'Infrared gas brooder', 'unit' => UnitOfMeasure::Piece, 'kobo' => [3_500_000, 7_500_000], 'condition' => ProductCondition::New],
        ['name' => 'Catfish fingerlings', 'unit' => UnitOfMeasure::Piece, 'kobo' => [3_500, 8_000], 'condition' => ProductCondition::Live, 'live' => true],
        ['name' => 'Red Sokoto goats, mature', 'unit' => UnitOfMeasure::Piece, 'kobo' => [6_500_000, 12_000_000], 'condition' => ProductCondition::Live, 'live' => true],
        ['name' => 'Newcastle disease vaccine, 1000 doses', 'unit' => UnitOfMeasure::Piece, 'kobo' => [250_000, 600_000], 'condition' => ProductCondition::New, 'perishable' => true],
        ['name' => 'Knapsack sprayer, 16 litre', 'unit' => UnitOfMeasure::Piece, 'kobo' => [1_200_000, 2_600_000], 'condition' => ProductCondition::New],
        ['name' => 'Improved maize seed, 10kg', 'unit' => UnitOfMeasure::Bag, 'kobo' => [900_000, 1_800_000], 'condition' => ProductCondition::New],
        ['name' => 'NPK 15-15-15 fertiliser, 50kg', 'unit' => UnitOfMeasure::Bag, 'kobo' => [3_500_000, 6_500_000], 'condition' => ProductCondition::New],
        ['name' => 'Plantain suckers', 'unit' => UnitOfMeasure::Bundle, 'kobo' => [250_000, 600_000], 'condition' => ProductCondition::Fresh, 'perishable' => true],
        ['name' => 'Weighing scale, 150kg platform', 'unit' => UnitOfMeasure::Piece, 'kobo' => [3_000_000, 7_000_000], 'condition' => ProductCondition::New],
        ['name' => 'Farm boots, pair', 'unit' => UnitOfMeasure::Piece, 'kobo' => [450_000, 900_000], 'condition' => ProductCondition::New],
        ['name' => 'Used tricycle for farm haulage', 'unit' => UnitOfMeasure::Piece, 'kobo' => [95_000_000, 160_000_000], 'condition' => ProductCondition::Used],
        ['name' => 'Vaccination round, per visit', 'unit' => UnitOfMeasure::Service, 'kobo' => [1_500_000, 4_000_000], 'condition' => ProductCondition::New],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $item = fake()->randomElement(self::CATALOGUE);
        $price = fake()->numberBetween($item['kobo'][0], $item['kobo'][1]);

        // Round to the nearest ₦50: nobody in this market quotes odd kobo.
        $price = (int) (round($price / 5000) * 5000);

        $isLive = $item['live'] ?? false;
        $isPerishable = $item['perishable'] ?? false;

        return [
            'seller_id' => SellerProfile::factory()->approved(),
            'category_id' => Category::factory(),
            'name' => $item['name'],
            'slug' => Str::slug($item['name']).'-'.Str::lower(Str::random(6)),
            // A closure, so the description follows whatever name the caller
            // actually ends up with. Generating it from the factory's own pick
            // would leave an overridden name describing something else.
            'description' => fn (array $attributes): string => $this->describe($attributes['name'] ?? $item['name']),
            'condition' => $item['condition'],
            'unit_of_measure' => $item['unit'],
            'price_kobo' => $price,
            'compare_at_price_kobo' => fake()->boolean(25) ? (int) round($price * 1.2 / 5000) * 5000 : null,
            'stock_quantity' => fake()->numberBetween(1, 400),
            'min_order_quantity' => fake()->randomElement([1, 1, 1, 5, 10]),
            'is_negotiable' => fake()->boolean(40),
            'requires_delivery_quote' => $price > 20_000_000 || fake()->boolean(15),
            'is_perishable' => $isPerishable,
            'is_live_animal' => $isLive,

            // Enforced by the form and the model alike: a live or perishable
            // listing always states how it will be handled.
            'handling_note' => ($isLive || $isPerishable)
                ? $this->handlingNote($isLive)
                : null,

            'status' => ProductStatus::Active,
            'published_at' => now()->subDays(fake()->numberBetween(0, 90)),
            'views_count' => fake()->numberBetween(0, 4000),
        ];
    }

    /**
     * Written rather than generated: Latin filler reads as placeholder text in
     * a catalogue that is meant to look like the real thing.
     */
    private function describe(string $name): string
    {
        return implode("\n\n", [
            $name.'. '.fake()->randomElement([
                'Fresh stock arrived this week and we are selling from the shop and the farm gate.',
                'We have carried this line for years and buyers come back for it.',
                'Same stock we supply to the bigger farms around here, sold in smaller quantities too.',
                'Straight from the manufacturer, so the price is better than the open market.',
                'Sold by the unit. Ask us about a discount if you are taking plenty.',
            ]),
            fake()->randomElement([
                'Pick-up at the shop, or we put it on a bike for delivery within town. For another state we send by park bus and you settle the transport.',
                'Delivery is free within the local government. Outside that we quote you before you pay, so there is no surprise.',
                'Come to the shop and check it yourself before you pay. We are open Monday to Saturday, 8am to 6pm.',
                'Call or send a WhatsApp message before you come so we can set it aside for you.',
            ]),
            fake()->randomElement([
                'Bulk buyers, please call ahead — we reserve for you and you collect at your convenience.',
                'We can help you load. Bring your own sacks if you have them.',
                'Ask for the manager if you are buying for a cooperative; there is a different price for that.',
                'Payment on collection, or transfer first and we hold it for two days.',
            ]),
        ]);
    }

    private function handlingNote(bool $isLive): string
    {
        return $isLive
            ? fake()->randomElement([
                'Birds are counted and boxed in your presence. Transport is your arrangement, or we book a bus for you at cost — travel before 10am so they arrive cool.',
                'Live delivery within the state only, early morning. Buyer must provide crates. We do not ship live birds by park bus after midday.',
                'Collected from the farm gate. Bring your own crates and come before 9am for the cool of the morning.',
            ])
            : fake()->randomElement([
                'Perishable. Dispatched same day in a cool box; please arrange collection within 24 hours.',
                'Keep refrigerated on arrival. We deliver within the city only, mornings.',
                'Sold fresh. Collection the same day, no storage beyond 48 hours.',
            ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::PendingReview,
            'published_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Rejected,
            'review_notes' => 'The photographs do not show the product being sold.',
            'published_at' => null,
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::OutOfStock,
            'stock_quantity' => 0,
        ]);
    }

    public function liveAnimal(): static
    {
        return $this->state(fn (): array => [
            'is_live_animal' => true,
            'condition' => ProductCondition::Live,
            'handling_note' => $this->handlingNote(true),
        ]);
    }

    public function perishable(): static
    {
        return $this->state(fn (): array => [
            'is_perishable' => true,
            'handling_note' => $this->handlingNote(false),
        ]);
    }

    public function negotiable(): static
    {
        return $this->state(fn (): array => ['is_negotiable' => true]);
    }

    public function pricedAt(int $kobo): static
    {
        return $this->state(fn (): array => ['price_kobo' => $kobo]);
    }
}
