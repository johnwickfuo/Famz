<?php

namespace Database\Factories;

use App\Models\SellerDeliveryRate;
use App\Models\SellerProfile;
use App\Support\Nigeria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellerDeliveryRate>
 */
class SellerDeliveryRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => SellerProfile::factory()->approved(),
            'state' => fake()->randomElement(Nigeria::states()),
            // ₦1,500 to ₦25,000, the range a bike or a bus parcel really costs.
            'fee_kobo' => fake()->numberBetween(150_000, 2_500_000),
            'is_active' => true,
        ];
    }
}
