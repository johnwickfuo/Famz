<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 50);
        $unitPrice = fake()->numberBetween(50_000, 5_000_000);

        return [
            'offerable_type' => (new Product)->getMorphClass(),
            'offerable_id' => Product::factory(),
            'initiator_id' => User::factory(),
            'responder_id' => User::factory(),
            'quantity' => $quantity,
            'unit_price_kobo' => $unitPrice,
            'total_price_kobo' => $quantity * $unitPrice,
            'status' => OfferStatus::Pending,
            'expires_at' => now()->addDays(3),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }
}
