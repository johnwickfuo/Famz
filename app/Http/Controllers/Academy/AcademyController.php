<?php

namespace App\Http\Controllers\Academy;

use App\Services\Platform\Seo;
use App\Enums\CourseLevel;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Enrolment;
use App\Services\Academy\EnrolmentService;
use App\Services\Academy\LessonAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public face of the academy.
 */
class AcademyController extends Controller
{
    public function __construct(
        private readonly EnrolmentService $enrolments,
        private readonly LessonAccess $access,
    ) {}

    public function home(Request $request): Response
    {
        $featured = Course::query()
            ->published()
            ->with('category')
            ->withCount('enrolments')
            ->ordered()
            ->limit(6)
            ->get();

        return Inertia::render('Academy/Home', [
            'categories' => CourseCategory::query()
                ->active()
                ->roots()
                ->ordered()
                ->withCount(['courses' => fn ($q) => $q->published()])
                ->get()
                ->map(fn (CourseCategory $category): array => [
                    'slug' => $category->slug,
                    'name' => $category->name,
                    'description' => $category->description,
                    'icon' => $category->icon,
                    'course_count' => $category->courses_count,
                ])->all(),

            'featured' => $featured->map(fn (Course $course): array => $this->card($course))->all(),

            // Whatever is not already above. With a young catalogue the two
            // lists would otherwise be the same three courses in a different
            // order, which reads as a page padded out rather than a page full.
            'newest' => Course::query()
                ->published()
                ->whereKeyNot($featured->modelKeys())
                ->with('category')
                ->latest('published_at')
                ->limit(4)
                ->get()
                ->map(fn (Course $course): array => $this->card($course))
                ->all(),

            'myCourses' => $this->myCourseCards($request, limit: 3),
        ]);
    }

    public function catalogue(Request $request): Response
    {
        $query = Course::query()->published()->with('category')->withCount('enrolments');

        if ($slug = $request->string('category')->toString()) {
            $category = CourseCategory::query()->where('slug', $slug)->first();

            if ($category !== null) {
                $query->whereIn('course_category_id', [$category->id, ...$category->descendantIds()]);
            }
        }

        if ($level = $request->string('level')->toString()) {
            $query->where('level', $level);
        }

        if ($request->boolean('free_only')) {
            $query->where(fn ($q) => $q->where('is_free', true)->orWhere('price_kobo', 0));
        }

        if ($term = trim($request->string('q')->toString())) {
            $query->where(fn ($q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhere('summary', 'like', "%{$term}%"));
        }

        $results = $query
            ->when(
                $request->string('sort')->toString() === 'popular',
                fn ($q) => $q->orderByDesc('enrolments_count'),
                fn ($q) => $q->ordered(),
            )
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Academy/Catalogue', [
            'courses' => collect($results->items())->map(fn (Course $c): array => $this->card($c))->all(),
            'pagination' => [
                'links' => $results->linkCollection()->toArray(),
                'from' => $results->firstItem(),
                'to' => $results->lastItem(),
                'total' => $results->total(),
            ],
            'filters' => [
                'category' => $request->string('category')->toString() ?: null,
                'level' => $request->string('level')->toString() ?: null,
                'free_only' => $request->boolean('free_only'),
                'q' => $term ?: null,
                'sort' => $request->string('sort')->toString() ?: 'newest',
            ],
            'categories' => CourseCategory::query()->active()->ordered()->get()
                ->map(fn (CourseCategory $c): array => ['value' => $c->slug, 'label' => $c->pathName()])
                ->all(),
            'levels' => collect(CourseLevel::cases())
                ->map(fn (CourseLevel $l): array => ['value' => $l->value, 'label' => $l->shortLabel()])
                ->all(),
        ]);
    }

    public function show(Request $request, Course $course): Response
    {
        abort_unless(
            $course->status->isPublic() || $this->enrolments->isEnrolled($request->user(), $course),
            404,
        );

        $course->load(['category', 'modules.lessons', 'quiz']);
        $course->loadCount('enrolments');

        $enrolment = $this->enrolments->enrolmentFor($request->user(), $course);

        app(Seo::class)
            ->title($course->title)
            ->description($course->summary)
            ->image($course->coverUrl())
            ->type('article');

        return Inertia::render('Academy/Course', [
            'course' => [
                ...$this->card($course),
                'description' => $course->description,
                'promo_video_url' => $course->promo_video_url,
                'what_you_will_learn' => $course->what_you_will_learn ?? [],
                'requirements' => $course->requirements ?? [],
                'has_quiz' => $course->quiz?->isUsable() ?? false,
                'pass_mark' => $course->quiz?->pass_mark_percent,
            ],
            'curriculum' => $course->modules->map(fn (CourseModule $module): array => [
                'title' => $module->title,
                'summary' => $module->summary,
                'lessons' => $module->lessons->map(fn (CourseLesson $lesson): array => [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'type' => $lesson->type->value,
                    'type_label' => $lesson->type->label(),
                    'duration' => $lesson->durationLabel(),
                    'is_preview' => $lesson->is_preview,
                    // Only preview lessons carry a link. Everything else is a
                    // title, which is what makes a curriculum worth reading
                    // without giving the course away.
                    'preview_url' => $lesson->is_preview && $lesson->hasFile()
                        ? $this->access->urlFor($lesson, $request->user())
                        : null,
                    'preview_text' => $lesson->is_preview && $lesson->type->value === 'text'
                        ? $lesson->content
                        : null,
                ])->all(),
            ])->all(),
            'enrolment' => $enrolment === null || ! $enrolment->isActive() ? null : [
                'progress' => $enrolment->progressPercent(),
                'player_url' => route('academy.player', $course->slug),
                'certificate_code' => $enrolment->certificate?->verification_code,
            ],
        ]);
    }

    /**
     * Everything somebody has bought.
     */
    public function mine(Request $request): Response
    {
        return Inertia::render('Academy/MyCourses', [
            'courses' => $this->myCourseCards($request),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function myCourseCards(Request $request, ?int $limit = null): array
    {
        if ($request->user() === null) {
            return [];
        }

        return Enrolment::query()
            ->ownedBy($request->user())
            ->active()
            ->with(['course.category', 'certificate'])
            ->latest('last_seen_at')
            ->latest('enrolled_at')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get()
            ->filter(fn (Enrolment $e): bool => $e->course !== null)
            ->map(fn (Enrolment $enrolment): array => [
                ...$this->card($enrolment->course),
                'progress' => $enrolment->progressPercent(),
                'player_url' => route('academy.player', $enrolment->course->slug),
                'completed' => $enrolment->completed_at !== null,
                'certificate_code' => $enrolment->certificate?->verification_code,
                'last_seen' => $enrolment->last_seen_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Course $course): array
    {
        return [
            'slug' => $course->slug,
            'title' => $course->title,
            'summary' => $course->summary,
            'cover' => $course->coverUrl(),
            'category' => $course->category?->name,
            'category_slug' => $course->category?->slug,
            'level' => $course->level->shortLabel(),
            'minutes' => $course->minutes(),
            'lesson_count' => $course->lessons()->count(),
            'price' => $course->price(),
            'price_kobo' => $course->price_kobo,
            'is_free' => $course->is_free || $course->price_kobo === 0,
            'students' => $course->enrolments_count ?? $course->enrolments()->count(),
            'url' => route('academy.course', $course->slug),
        ];
    }
}
