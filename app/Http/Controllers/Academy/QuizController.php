<?php

namespace App\Http\Controllers\Academy;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\Academy\CertificateIssuer;
use App\Services\Academy\EnrolmentService;
use App\Services\Academy\QuizGrader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Sitting the final quiz.
 *
 * The page never carries which options are correct. Marking happens on the
 * server from the stored options, because anything the browser is trusted to
 * work out is a certificate anybody can mint with the console open.
 */
class QuizController extends Controller
{
    public function __construct(
        private readonly EnrolmentService $enrolments,
        private readonly QuizGrader $grader,
        private readonly CertificateIssuer $issuer,
    ) {}

    public function show(Request $request, Course $course): Response|RedirectResponse
    {
        $enrolment = $this->enrolments->enrolmentFor($request->user(), $course);

        abort_if($enrolment === null || ! $enrolment->isActive(), 403);

        $quiz = $course->quiz;

        if ($quiz === null || ! $quiz->isUsable()) {
            return redirect()
                ->route('academy.player', $course->slug)
                ->with('info', __('There is no quiz on this course.'));
        }

        $quiz->load('questions.options');
        $best = $enrolment->bestQuizAttempt();

        return Inertia::render('Academy/Quiz', [
            'course' => ['slug' => $course->slug, 'title' => $course->title],
            'quiz' => [
                'title' => $quiz->title,
                'description' => $quiz->description,
                'pass_mark' => $quiz->pass_mark_percent,
                'question_count' => $quiz->questions->count(),
                'attempts_left' => $quiz->attemptsLeftFor($enrolment),
                'may_attempt' => $quiz->allowsAnotherAttempt($enrolment),
                'passed' => $enrolment->hasPassedQuiz(),
            ],
            'questions' => $quiz->questions->map(fn (QuizQuestion $question): array => [
                'id' => $question->id,
                'question' => $question->question,
                'type' => $question->type->value,
                'type_label' => $question->type->label(),
                'multiple' => $question->type->allowsMultiple(),
                // Text and id only. Which one is right never leaves the server
                // until the attempt has been marked.
                'options' => $question->options->map(fn ($option): array => [
                    'id' => $option->id,
                    'text' => $option->text,
                ])->all(),
            ])->all(),
            'lastAttempt' => $best === null ? null : [
                'score' => $best->score_percent,
                'passed' => $best->passed,
                'correct' => $best->correct_count,
                'total' => $best->question_count,
                'at' => $best->attempted_at?->format('j M Y, H:i'),
            ],
            'certificate' => [
                'earned' => $this->issuer->isEarned($enrolment),
                'outstanding' => $this->issuer->outstanding($enrolment),
                'code' => $enrolment->certificate?->verification_code,
            ],
        ]);
    }

    public function submit(Request $request, Course $course): RedirectResponse
    {
        $enrolment = $this->enrolments->enrolmentFor($request->user(), $course);

        abort_if($enrolment === null || ! $enrolment->isActive(), 403);

        $quiz = $course->quiz;

        abort_if($quiz === null, 404);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable'],
        ]);

        try {
            $attempt = $this->grader->grade($enrolment, $quiz, $validated['answers']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            $attempt->passed ? 'success' : 'info',
            $attempt->passed
                ? __('Passed, with :score%.', ['score' => $attempt->score_percent])
                : __('You scored :score%. The pass mark is :mark%.', [
                    'score' => $attempt->score_percent,
                    'mark' => $quiz->pass_mark_percent,
                ]),
        );
    }

    /**
     * What was right and what was not, after the fact.
     *
     * Shown once an attempt exists, right or wrong: a quiz nobody can learn
     * from is a gate rather than a lesson.
     */
    public function review(Request $request, Course $course): Response|RedirectResponse
    {
        $enrolment = $this->enrolments->enrolmentFor($request->user(), $course);

        abort_if($enrolment === null || ! $enrolment->isActive(), 403);

        $attempt = $enrolment->quizAttempts()->latest('id')->first();

        if ($attempt === null) {
            return redirect()->route('academy.quiz', $course->slug);
        }

        $quiz = $course->quiz;
        $quiz?->load('questions.options');

        $chosen = collect($attempt->answers ?? [])->keyBy('question_id');

        return Inertia::render('Academy/QuizReview', [
            'course' => ['slug' => $course->slug, 'title' => $course->title],
            'attempt' => [
                'score' => $attempt->score_percent,
                'passed' => $attempt->passed,
                'correct' => $attempt->correct_count,
                'total' => $attempt->question_count,
                'at' => $attempt->attempted_at?->format('j M Y, H:i'),
            ],
            'questions' => ($quiz?->questions ?? collect())->map(function (QuizQuestion $question) use ($chosen): array {
                $mine = collect($chosen->get($question->id)['chosen'] ?? [])->map(fn ($id): int => (int) $id);

                return [
                    'question' => $question->question,
                    'explanation' => $question->explanation,
                    'was_right' => (bool) ($chosen->get($question->id)['correct'] ?? false),
                    'options' => $question->options->map(fn ($option): array => [
                        'text' => $option->text,
                        'is_correct' => $option->is_correct,
                        'was_chosen' => $mine->contains($option->id),
                    ])->all(),
                ];
            })->all(),
            'mayRetry' => $quiz?->allowsAnotherAttempt($enrolment) ?? false,
        ]);
    }
}
