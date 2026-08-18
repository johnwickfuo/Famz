<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPriceTier>
 */
class ProductPriceTierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'min_quantity' => fake()->randomElement([5, 10, 20, 50, 100]),
            'unit_price_kobo' => fake()->numberBetween(50_000, 2_000_000),
        ];
    }
}
