<?php

namespace Database\Factories;

use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\CourseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->randomElement([
            'Brooder management for day-old chicks',
            'Costing a bag of feed properly',
            'Keeping records that a bank will read',
            'Spotting coccidiosis before it spreads',
            'Building a deep litter house on a budget',
        ]).' '.fake()->unique()->numerify('##');

        return [
            'course_category_id' => CourseCategory::factory(),
            'title' => $title,
            'slug' => Course::uniqueSlug($title),
            'summary' => fake()->sentence(14),
            'description' => fake()->paragraphs(3, true),
            'price_kobo' => fake()->randomElement([500_000, 1_200_000, 2_500_000]),
            'currency' => 'NGN',
            'is_free' => false,
            'level' => fake()->randomElement(CourseLevel::cases()),
            'what_you_will_learn' => [
                'How to set a brooder up the night before',
                'What a healthy chick looks like at day three',
                'When to widen the ring',
            ],
            'requirements' => ['A pen and a notebook'],
            'status' => CourseStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (): array => ['is_free' => true, 'price_kobo' => 0]);
    }
}
