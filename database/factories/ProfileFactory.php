<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Support\Nigeria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'phone' => '0'.fake()->numerify('80########'),
            'whatsapp' => '0'.fake()->numerify('80########'),
            'state' => fake()->randomElement(Nigeria::states()),
            'lga' => fake()->city(),
            'bio' => fake()->sentence(12),
        ];
    }
}
