<?php

namespace Database\Factories;

use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployerProfile>
 */
class EmployerProfileFactory extends Factory
{
    protected $model = EmployerProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_name' => fake()->company().' Farms',
            'business_type' => fake()->randomElement(['Layer poultry', 'Broiler poultry', 'Fish farm', 'Mixed farm']),
            'state' => fake()->randomElement(['Oyo', 'Kano', 'Ogun', 'Kaduna', 'Enugu']),
            'lga' => fake()->randomElement(['Akinyele', 'Ido', 'Dala', 'Ifo']),
            'about' => fake()->sentence(14),
            'contact_person' => fake()->name(),
            'phone' => '080'.fake()->numerify('########'),
            'email' => fake()->safeEmail(),
            'is_active' => true,
        ];
    }
}
