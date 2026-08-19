<?php

namespace Database\Factories;

use App\Enums\BuyerRequestStatus;
use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\User;
use App\Support\Nigeria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuyerRequest>
 */
class BuyerRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->randomElement([
            'Wanted: 200 bags of layers mash',
            'Looking for 500 day-old broiler chicks',
            'Need a second-hand incubator, 1000 eggs',
            'Buying maize offal in bulk, weekly',
            'Wanted: point-of-lay pullets, 300 birds',
        ]);

        $min = fake()->numberBetween(50_000, 2_000_000);

        return [
            'user_id' => User::factory(),
            'reference' => BuyerRequest::newReference(),
            'title' => $title,
            'slug' => BuyerRequest::uniqueSlug($title.' '.fake()->unique()->numerify('####')),
            'description' => fake()->paragraph(4),
            'category_id' => Category::factory(),
            'quantity' => fake()->numberBetween(10, 500),
            'unit' => fake()->randomElement(['bag', 'bird', 'crate', 'kg', 'piece']),
            'budget_min_kobo' => $min,
            'budget_max_kobo' => $min + fake()->numberBetween(0, 500_000),
            'delivery_state' => fake()->randomElement(Nigeria::states()),
            'delivery_lga' => fake()->city(),
            'needed_by' => fake()->dateTimeBetween('+1 week', '+2 months'),
            'accepts_partial_fulfilment' => fake()->boolean(60),
            'status' => BuyerRequestStatus::PendingApproval,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (): array => [
            'status' => BuyerRequestStatus::Open,
            'approved_at' => now()->subDay(),
            'expires_at' => now()->addDays(13),
        ]);
    }

    public function expiringIn(int $days): static
    {
        return $this->open()->state(fn (): array => [
            'expires_at' => now()->addDays($days),
        ]);
    }
}
