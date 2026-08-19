<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => 'Final assessment',
            'description' => 'A few questions to finish.',
            'pass_mark_percent' => 70,
            'max_attempts' => null,
            'is_required_for_certificate' => true,
            'is_active' => true,
        ];
    }
}
