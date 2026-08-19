<?php

namespace Database\Factories;

use App\Models\CourseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseCategory>
 */
class CourseCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Poultry management', 'Feed and nutrition', 'Farm business',
            'Crop production', 'Animal health', 'Record keeping',
        ]).' '.fake()->unique()->numerify('##');

        return [
            'name' => $name,
            'slug' => CourseCategory::uniqueSlug($name),
            'description' => fake()->sentence(10),
            'is_active' => true,
        ];
    }
}
