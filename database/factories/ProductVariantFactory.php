<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['25kg bag', '50kg bag', 'Pullet', 'Cockerel', 'Small', 'Large']),
            'price_delta_kobo' => fake()->randomElement([-200_000, -50_000, 0, 100_000, 350_000]),
            'stock_quantity' => fake()->numberBetween(0, 120),
            'sku' => null,
            'sort_order' => 0,
        ];
    }
}
