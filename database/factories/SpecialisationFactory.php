<?php

namespace Database\Factories;

use App\Models\Specialisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Specialisation>
 */
class SpecialisationFactory extends Factory
{
    protected $model = Specialisation::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'sector' => 'poultry',
            'description' => $this->faker->sentence(),
            'keywords' => [$name],
            'is_active' => true,
        ];
    }
}
