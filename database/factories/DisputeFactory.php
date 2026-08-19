<?php

namespace Database\Factories;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sub_order_id' => SubOrder::factory(),
            'raised_by' => User::factory(),
            'reason' => fake()->randomElement(DisputeReason::cases()),
            'description' => fake()->sentence(12),
            'status' => DisputeStatus::Open,
        ];
    }

    public function underReview(): static
    {
        return $this->state(fn (): array => ['status' => DisputeStatus::UnderReview]);
    }
}
