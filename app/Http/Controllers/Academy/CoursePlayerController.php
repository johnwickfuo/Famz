<?php

namespace App\Http\Controllers\Academy;

use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Enrolment;
use App\Services\Academy\CertificateIssuer;
use App\Services\Academy\EnrolmentService;
use App\Services\Academy\LessonAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The course player.
 *
 * The sidebar always lists every lesson — a student should be able to see what
 * they bought — but only the lesson actually being viewed carries a content
 * URL, and that URL is minted fresh, signed, and good for five minutes. Handing
 * the whole course's links to the browser at once would put a working copy of
 * every file in one page's source.
 */
class CoursePlayerController extends Controller
{
    public function __construct(
        private readonly EnrolmentService $enrolments,
        private readonly LessonAccess $access,
        private readonly CertificateIssuer $issuer,
    ) {}

    public function show(Request $request, Course $course, ?CourseLesson $lesson = null): Response|RedirectResponse
    {
        $enrolment = $this->enrolments->enrolmentFor($request->user(), $course);

        if ($enrolment === null || ! $enrolment->isActive()) {
            return redirect()
                ->route('academy.course', $course->slug)
                ->with('info', __('You need to be enrolled to open this course.'));
        }

        abort_unless($course->isReadableByEnrolled(), 404);

        $course->load(['modules.lessons', 'quiz.questions']);

        $lesson = $this->resolveLesson($enrolment, $course, $lesson);

        if ($lesson !== null) {
            $this->enrolments->touch($enrolment, $lesson);
        }

        $completed = $enrolment->completedLessonIds();
        $ordered = $course->lessons()->get();
        $position = $lesson === null ? null : $ordered->search(fn (CourseLesson $l): bool => $l->is($lesson));

        return Inertia::render('Academy/Player', [
            'course' => [
                'slug' => $course->slug,
                'title' => $course->title,
                'lesson_count' => $ordered->count(),
            ],
            'enrolment' => [
                'reference' => $enrolment->reference,
                'progress' => $enrolment->progressPercent(),
                'completed_count' => $completed->count(),
                'certificate_code' => $enrolment->certificate?->verification_code,
                'certificate_earned' => $this->issuer->isEarned($enrolment),
                'certificate_outstanding' => $this->issuer->outstanding($enrolment),
            ],
            'modules' => $course->modules->map(fn (CourseModule $module): array => [
                'id' => $module->id,
                'title' => $module->title,
                'lessons' => $module->lessons->map(fn (CourseLesson $item): array => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'type' => $item->type->value,
                    'type_label' => $item->type->label(),
                    'duration' => $item->durationLabel(),
                    'is_completed' => $completed->contains($item->id),
                    'is_current' => $lesson?->is($item) ?? false,
                    'url' => route('academy.player.lesson', [$course->slug, $item->id]),
                ])->all(),
            ])->all(),
            'lesson' => $lesson === null ? null : $this->lessonPayload($enrolment, $lesson),
            'navigation' => [
                'previous' => $position !== null && $position > 0
                    ? route('academy.player.lesson', [$course->slug, $ordered[$position - 1]->id])
                    : null,
                'next' => $position !== null && $position < $ordered->count() - 1
                    ? route('academy.player.lesson', [$course->slug, $ordered[$position + 1]->id])
                    : null,
            ],
            'quiz' => $course->quiz === null || ! $course->quiz->isUsable() ? null : [
                'title' => $course->quiz->title,
                'url' => route('academy.quiz', $course->slug),
                'passed' => $enrolment->hasPassedQuiz(),
                'best_score' => $enrolment->bestQuizAttempt()?->score_percent,
                'attempts_left' => $course->quiz->attemptsLeftFor($enrolment),
            ],
        ]);
    }

    /**
     * Note where somebody got to in a lesson.
     *
     * Called by the player as a video plays. Deliberately cheap and silent —
     * it returns nothing to render, because it fires every few seconds.
     */
    public function position(Request $request, Course $course, CourseLesson $lesson): JsonResponse
    {
        $enrolment = $this->requireEnrolment($request, $course);

        $validated = $request->validate([
            'position' => ['required', 'integer', 'min:0', 'max:86400'],
        ]);

        $this->enrolments->touch($enrolment, $lesson, (int) $validated['position']);

        return response()->json(['ok' => true]);
    }

    public function complete(Request $request, Course $course, CourseLesson $lesson): RedirectResponse
    {
        $enrolment = $this->requireEnrolment($request, $course);

        $completed = $request->boolean('completed', true);

        try {
            $this->enrolments->setCompleted($enrolment, $lesson, $completed);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    /**
     * A fresh content link for the lesson on screen.
     *
     * Separate from the page render so a player left open for an hour can ask
     * for a new one rather than making the first link long-lived.
     */
    public function contentUrl(Request $request, Course $course, CourseLesson $lesson): JsonResponse
    {
        $enrolment = $this->requireEnrolment($request, $course);

        abort_unless($lesson->module?->course_id === $course->getKey(), 404);
        abort_unless($lesson->hasFile(), 404);

        return response()->json([
            'url' => $this->access->urlFor($lesson, $request->user()),
            'expires_in' => LessonAccess::TTL_MINUTES * 60,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonPayload(Enrolment $enrolment, CourseLesson $lesson): array
    {
        $progress = $enrolment->progress()
            ->where('course_lesson_id', $lesson->getKey())
            ->first();

        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'type' => $lesson->type->value,
            'type_label' => $lesson->type->label(),
            'duration' => $lesson->durationLabel(),
            'is_completed' => (bool) $progress?->is_completed,
            'last_position' => (int) ($progress?->last_position ?? 0),
            // Text lessons carry their content inline; everything else is
            // fetched through a signed link the moment it is needed.
            'content' => $lesson->type === LessonType::Text ? $lesson->content : null,
            'content_url' => $lesson->hasFile()
                ? $this->access->urlFor($lesson, $enrolment->user)
                : null,
            'refresh_url' => route('academy.player.content', [$enrolment->course->slug, $lesson->id]),
            'complete_url' => route('academy.player.complete', [$enrolment->course->slug, $lesson->id]),
            'position_url' => route('academy.player.position', [$enrolment->course->slug, $lesson->id]),
        ];
    }

    private function resolveLesson(Enrolment $enrolment, Course $course, ?CourseLesson $lesson): ?CourseLesson
    {
        if ($lesson !== null && $lesson->module?->course_id === $course->getKey()) {
            return $lesson;
        }

        // No lesson asked for: drop them back where they were.
        return $this->enrolments->resumeLesson($enrolment);
    }

    private function requireEnrolment(Request $request, Course $course): Enrolment
    {
        $enrolment = $this->enrolments->enrolmentFor($request->user(), $course);

        abort_if($enrolment === null || ! $enrolment->isActive(), 403);

        return $enrolment;
    }
}
