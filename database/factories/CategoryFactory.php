<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'icon' => null,
            'description' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(0, 200),
            'is_active' => true,
        ];
    }

    public function childOf(Category $parent): static
    {
        return $this->state(fn (): array => ['parent_id' => $parent->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
