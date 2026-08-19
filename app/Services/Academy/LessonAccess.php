<?php

namespace App\Services\Academy;

use App\Models\CourseLesson;
use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Who may see a lesson's file, and for how long.
 *
 * Every rule about reaching course material lives here, so there is one answer
 * to the question rather than one per route. The link a player is handed is
 * signed and short-lived; the controller re-checks the enrolment anyway, because
 * a signature proves the URL was minted by us, not that the person holding it
 * is still allowed to use it.
 *
 * None of this is DRM. A determined student can screen-record a video and
 * photograph a handout, and no amount of cleverness here changes that. The
 * point is to make casual sharing — forwarding a link to a WhatsApp group —
 * not work, and to put the buyer's own name on anything that does get passed
 * around.
 */
class LessonAccess
{
    /**
     * How long a content link is good for. Deliberately short: long enough to
     * start a video, not long enough to paste into a group chat and still work
     * when somebody opens it.
     */
    public const TTL_MINUTES = 5;

    /**
     * The enrolment that entitles this user to this lesson, if any.
     */
    public function enrolmentFor(?User $user, CourseLesson $lesson): ?Enrolment
    {
        if ($user === null) {
            return null;
        }

        $course = $lesson->course();

        if ($course === null || ! $course->isReadableByEnrolled()) {
            return null;
        }

        return Enrolment::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where('course_id', $course->getKey())
            ->first();
    }

    /**
     * Whether this user may open this lesson at all.
     *
     * A preview lesson is open to anybody — that is what makes a curriculum
     * list worth reading before buying — but only on a course the public can
     * see. A preview on a draft course is not a preview, it is a leak.
     */
    public function may(?User $user, CourseLesson $lesson): bool
    {
        if ($this->isOpenPreview($lesson)) {
            return true;
        }

        return $this->enrolmentFor($user, $lesson) !== null;
    }

    public function isOpenPreview(CourseLesson $lesson): bool
    {
        return $lesson->is_preview && $lesson->course()?->status->isPublic() === true;
    }

    /**
     * A signed, expiring link to a lesson's file.
     *
     * Bound to the user as well as the lesson: a link forwarded to somebody
     * else fails the ownership check even inside the five minutes, and the
     * watermark on the PDF would have named the sender anyway.
     */
    public function urlFor(CourseLesson $lesson, ?User $user = null): string
    {
        return URL::temporarySignedRoute(
            'academy.lesson.file',
            now()->addMinutes(self::TTL_MINUTES),
            [
                'lesson' => $lesson->getKey(),
                'u' => $user?->getKey() ?? 0,
            ],
        );
    }
}
