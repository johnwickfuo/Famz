<?php

namespace Database\Factories;

use App\Enums\QuizQuestionType;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizQuestion>
 */
class QuizQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'question' => fake()->sentence(9).'?',
            'type' => QuizQuestionType::SingleChoice,
            'explanation' => fake()->sentence(12),
            'sort_order' => 0,
        ];
    }

    /**
     * A question with one right answer and two wrong ones.
     */
    public function withOptions(int $wrong = 2): static
    {
        return $this->afterCreating(function (QuizQuestion $question) use ($wrong): void {
            $question->options()->create(['text' => 'The right one', 'is_correct' => true, 'sort_order' => 0]);

            foreach (range(1, $wrong) as $index) {
                $question->options()->create([
                    'text' => 'A wrong one '.$index,
                    'is_correct' => false,
                    'sort_order' => $index,
                ]);
            }
        });
    }
}
