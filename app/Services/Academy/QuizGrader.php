<?php

namespace App\Services\Academy;

use App\Models\Enrolment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Marking the final quiz.
 *
 * Everything is decided server-side from the stored options. The browser sends
 * which options were chosen and nothing else — no scores, no correctness, no
 * "passed" flag — because anything the client is trusted to compute is a
 * certificate anybody can mint with the developer console open.
 */
class QuizGrader
{
    /**
     * Mark an attempt.
     *
     * @param  array<int|string, array<int, int|string>|int|string>  $answers  question id => chosen option ids
     */
    public function grade(Enrolment $enrolment, Quiz $quiz, array $answers): QuizAttempt
    {
        if ($quiz->course_id !== $enrolment->course_id) {
            throw new RuntimeException(__('That quiz belongs to another course.'));
        }

        if (! $enrolment->isActive()) {
            throw new RuntimeException(__('You are not enrolled on this course.'));
        }

        if (! $quiz->isUsable()) {
            throw new RuntimeException(__('This quiz is not ready yet.'));
        }

        if (! $quiz->allowsAnotherAttempt($enrolment)) {
            throw new RuntimeException($enrolment->hasPassedQuiz()
                ? __('You have already passed this quiz.')
                : __('You have used all your attempts at this quiz.'));
        }

        $questions = $quiz->questions()->with('options')->get();

        if ($questions->isEmpty()) {
            // A quiz with no questions would score 0 out of 0 and pass
            // everybody, which is how a certificate reaches somebody who
            // answered nothing.
            throw new RuntimeException(__('This quiz has no questions yet.'));
        }

        $correct = 0;
        $recorded = [];

        foreach ($questions as $question) {
            $chosen = $this->chosenFor($answers, $question);
            $isRight = $question->isAnsweredCorrectlyBy($chosen);

            $correct += $isRight ? 1 : 0;

            $recorded[] = [
                'question_id' => $question->getKey(),
                'chosen' => array_values($chosen),
                'correct' => $isRight,
            ];
        }

        $score = (int) round(($correct / $questions->count()) * 100);
        $passed = $score >= $quiz->pass_mark_percent;

        return DB::transaction(function () use ($enrolment, $quiz, $score, $correct, $questions, $passed, $recorded): QuizAttempt {
            $attempt = new QuizAttempt;

            $attempt->forceFill([
                'enrolment_id' => $enrolment->getKey(),
                'quiz_id' => $quiz->getKey(),
                'score_percent' => $score,
                'correct_count' => $correct,
                'question_count' => $questions->count(),
                'passed' => $passed,
                'answers' => $recorded,
                'attempted_at' => now(),
            ])->save();

            return $attempt->refresh();
        });
    }

    /**
     * What was chosen for one question, normalised.
     *
     * The form sends a single value for a radio and an array for checkboxes;
     * both become an array here so the comparison has one shape.
     *
     * @param  array<int|string, mixed>  $answers
     * @return array<int, int>
     */
    private function chosenFor(array $answers, QuizQuestion $question): array
    {
        $given = $answers[$question->getKey()] ?? $answers[(string) $question->getKey()] ?? [];

        return collect(is_array($given) ? $given : [$given])
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }
}
