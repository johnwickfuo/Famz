<?php

namespace App\Services\Academy;

use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\Enrolment;
use App\Models\LessonProgress;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Putting somebody on a course, and keeping track of where they got to.
 */
class EnrolmentService
{
    /**
     * The wording somebody has to agree to before buying.
     *
     * Stored on the enrolment as it stood that day, because an argument six
     * months later is about what they were shown, not about what the page says
     * now.
     */
    public function termsText(): string
    {
        return __('This is a one-time purchase with lifetime access to the course. All sales are final — there are no refunds once you have paid.');
    }

    public function enrolmentFor(?User $user, Course $course): ?Enrolment
    {
        if ($user === null) {
            return null;
        }

        return Enrolment::query()
            ->where('user_id', $user->getKey())
            ->where('course_id', $course->getKey())
            ->first();
    }

    public function isEnrolled(?User $user, Course $course): bool
    {
        return $this->enrolmentFor($user, $course)?->isActive() === true;
    }

    /**
     * Open a course to somebody.
     *
     * Idempotent: a webhook delivered twice, or an administrator granting
     * access to somebody who already has it, must not produce a second
     * enrolment or reset anybody's progress.
     */
    public function enrol(
        User $user,
        Course $course,
        ?Order $order = null,
        int $pricePaidKobo = 0,
        ?Request $consentFrom = null,
    ): Enrolment {
        return DB::transaction(function () use ($user, $course, $order, $pricePaidKobo, $consentFrom): Enrolment {
            $enrolment = Enrolment::query()->firstOrNew([
                'user_id' => $user->getKey(),
                'course_id' => $course->getKey(),
            ]);

            // Already open. Nothing to do, and certainly nothing to reset.
            if ($enrolment->exists && $enrolment->isActive()) {
                return $enrolment;
            }

            $enrolment->forceFill([
                'order_id' => $order?->getKey() ?? $enrolment->order_id,
                'order_reference' => $order?->reference ?? $enrolment->order_reference,
                // Coalesced to zero rather than left null: a free enrolment
                // passes nothing and a new model has nothing, and the column
                // does not take null.
                'price_paid_kobo' => $pricePaidKobo ?: ($enrolment->price_paid_kobo ?? 0),
                'currency' => $course->currency,
                'enrolled_at' => now(),
            ]);

            if ($consentFrom !== null) {
                $enrolment->forceFill([
                    'terms_accepted' => true,
                    'terms_accepted_at' => now(),
                    'terms_accepted_text' => $this->termsText(),
                    'terms_accepted_ip' => $consentFrom->ip(),
                ]);
            }

            $enrolment->save();

            return $enrolment->refresh();
        });
    }

    /**
     * Record a pending enrolment while a payment is in flight.
     *
     * Deliberately without `enrolled_at`: the row exists so the consent is
     * captured at the moment it was given, but it opens nothing until the money
     * clears.
     */
    public function reserve(User $user, Course $course, Request $consentFrom): Enrolment
    {
        $enrolment = Enrolment::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'course_id' => $course->getKey(),
        ]);

        if ($enrolment->exists && $enrolment->isActive()) {
            throw new RuntimeException(__('You already have this course.'));
        }

        $enrolment->forceFill([
            'currency' => $course->currency,
            'terms_accepted' => true,
            'terms_accepted_at' => now(),
            'terms_accepted_text' => $this->termsText(),
            'terms_accepted_ip' => $consentFrom->ip(),
        ])->save();

        return $enrolment->refresh();
    }

    // -----------------------------------------------------------------------
    // Progress
    // -----------------------------------------------------------------------

    /**
     * Note that somebody looked at a lesson, and where they got to in it.
     */
    public function touch(Enrolment $enrolment, CourseLesson $lesson, ?int $position = null): LessonProgress
    {
        $this->assertLessonBelongs($enrolment, $lesson);

        $progress = LessonProgress::query()->firstOrNew([
            'enrolment_id' => $enrolment->getKey(),
            'course_lesson_id' => $lesson->getKey(),
        ]);

        if ($position !== null) {
            $progress->last_position = max(0, $position);
        }

        $progress->save();

        $enrolment->forceFill([
            'last_lesson_id' => $lesson->getKey(),
            'last_seen_at' => now(),
        ])->save();

        return $progress;
    }

    /**
     * Mark a lesson done, or undone.
     */
    public function setCompleted(Enrolment $enrolment, CourseLesson $lesson, bool $completed = true): LessonProgress
    {
        $this->assertLessonBelongs($enrolment, $lesson);

        $progress = LessonProgress::query()->firstOrNew([
            'enrolment_id' => $enrolment->getKey(),
            'course_lesson_id' => $lesson->getKey(),
        ]);

        $progress->forceFill([
            'is_completed' => $completed,
            'completed_at' => $completed ? ($progress->completed_at ?? now()) : null,
        ])->save();

        $this->syncCompletion($enrolment->refresh());

        return $progress;
    }

    /**
     * Keep `completed_at` in step with the lessons.
     *
     * Both directions: adding a lesson to a course un-finishes it for everybody
     * who had finished, which is true and better said than hidden.
     */
    public function syncCompletion(Enrolment $enrolment): void
    {
        $finished = $enrolment->hasFinishedEveryLesson();

        if ($finished && $enrolment->completed_at === null) {
            $enrolment->forceFill(['completed_at' => now()])->save();

            return;
        }

        if (! $finished && $enrolment->completed_at !== null) {
            $enrolment->forceFill(['completed_at' => null])->save();
        }
    }

    /**
     * Where to drop somebody back into a course.
     *
     * Their last lesson if it is still there, otherwise the first thing they
     * have not finished, otherwise the beginning.
     */
    public function resumeLesson(Enrolment $enrolment): ?CourseLesson
    {
        $lessons = $enrolment->course->lessons()->get();

        if ($lessons->isEmpty()) {
            return null;
        }

        if ($enrolment->last_lesson_id !== null) {
            $last = $lessons->firstWhere('id', $enrolment->last_lesson_id);

            if ($last !== null) {
                return $last;
            }
        }

        $done = $enrolment->completedLessonIds();

        return $lessons->first(fn (CourseLesson $lesson): bool => ! $done->contains($lesson->getKey()))
            ?? $lessons->first();
    }

    private function assertLessonBelongs(Enrolment $enrolment, CourseLesson $lesson): void
    {
        if ($lesson->module?->course_id !== $enrolment->course_id) {
            throw new RuntimeException(__('That lesson is not part of this course.'));
        }
    }
}
