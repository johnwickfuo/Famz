<?php

namespace Database\Factories;

use App\Enums\LessonType;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseLesson>
 */
class CourseLessonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_module_id' => CourseModule::factory(),
            'title' => fake()->sentence(4),
            'type' => LessonType::Text,
            'content' => fake()->paragraphs(2, true),
            'duration_seconds' => fake()->numberBetween(120, 900),
            'is_preview' => false,
            'sort_order' => 0,
        ];
    }

    public function pdf(string $path = 'handouts/sample.pdf'): static
    {
        return $this->state(fn (): array => [
            'type' => LessonType::Pdf,
            'content' => null,
            'file_path' => $path,
            'file_name' => 'handout.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);
    }

    public function video(string $path = 'videos/sample.mp4'): static
    {
        return $this->state(fn (): array => [
            'type' => LessonType::Video,
            'content' => null,
            'file_path' => $path,
            'file_name' => 'lesson.mp4',
            'mime_type' => 'video/mp4',
            'file_size' => 4096,
        ]);
    }

    public function preview(): static
    {
        return $this->state(fn (): array => ['is_preview' => true]);
    }
}
