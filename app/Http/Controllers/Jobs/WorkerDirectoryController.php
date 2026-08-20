<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\RatingParty;
use App\Enums\WorkerAvailability;
use App\Enums\WorkTypeWanted;
use App\Http\Controllers\Controller;
use App\Models\JobListing;
use App\Models\JobRating;
use App\Models\WorkerProfile;
use App\Models\WorkerSkill;
use App\Services\Jobs\JobMatcher;
use App\Services\Jobs\WorkerContactGuard;
use App\Support\Nigeria;
use Illuminate\Http\Request;
use App\Services\Platform\Seo;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The worker directory, which is for employers only.
 *
 * Not because a worker's name is secret, but because a public crawlable index
 * of people looking for work is the raw material for exactly the harvesting
 * this module exists to prevent. The page is also of no use to anybody who is
 * not hiring.
 *
 * Contact details are never in the payload unless WorkerContactGuard put them
 * there, and it records every time it does. There is no branch in this
 * controller that assembles a number by hand.
 */
class WorkerDirectoryController extends Controller
{
    public function __construct(
        private readonly WorkerContactGuard $guard,
        private readonly JobMatcher $matcher,
    ) {}

    /**
     * Browse workers.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkerProfile::class);

        $filters = $request->validate([
            'state' => ['nullable', 'string', 'max:64'],
            'skill' => ['nullable', 'integer', 'exists:worker_skills,id'],
            'work_type' => ['nullable', 'string', 'in:'.implode(',', WorkTypeWanted::values())],
            'availability' => ['nullable', 'string', 'in:'.implode(',', WorkerAvailability::values())],
            'relocating' => ['nullable', 'boolean'],
            'listing' => ['nullable', 'string', 'exists:job_listings,slug'],
        ]);

        $workers = WorkerProfile::query()
            ->openToWork()
            ->with('skills')
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('state', $state))
            ->when($filters['work_type'] ?? null, fn ($q, $type) => $q->where('work_type_wanted', $type))
            ->when($filters['availability'] ?? null, fn ($q, $when) => $q->where('availability', $when))
            ->when($filters['relocating'] ?? null, fn ($q) => $q->where('willing_to_relocate', true))
            ->when($filters['skill'] ?? null, fn ($q, $skill) => $q->whereHas(
                'skills',
                fn ($s) => $s->where('worker_skills.id', $skill),
            ))
            ->latest('id')
            ->get();

        /*
         * Ranked against one of the employer's own listings when they pick one.
         * This is the "when an employer posts a listing, surface matching
         * workers" path: the listing is the query, and the ranking explains
         * itself rather than presenting an unexplained order.
         */
        $against = $this->listingFor($request, $filters['listing'] ?? null);

        if ($against !== null) {
            $ranked = $this->matcher->rankWorkersFor($against, $workers);

            $cards = $ranked->map(fn (array $row): array => [
                ...$row['worker']->publicCard(),
                'match_score' => $row['score'],
                'match_reason' => $this->matcher->explain($row['reasons']),
            ])->all();
        } else {
            $cards = $workers->map(fn (WorkerProfile $worker): array => $worker->publicCard())->all();
        }

        $viewer = $request->user();

        return Inertia::render('Jobs/Workers/Index', [
            // publicCard() has no contact field. Nothing is stripped here
            // because nothing was ever added.
            'workers' => $cards,
            'filters' => $filters,
            'states' => Nigeria::states(),
            'skills' => WorkerSkill::grouped(),
            'workTypes' => WorkTypeWanted::options(),
            'availabilities' => WorkerAvailability::options(),
            'myListings' => $this->employerListings($request),
            'matchedAgainst' => $against?->title,
            'allowance' => [
                'remaining' => $viewer === null ? null : $this->guard->remainingToday($viewer),
                'limit' => $this->guard->dailyLimit(),
            ],
            'notice' => JobBoardController::notice(),
        ]);
    }

    /**
     * One worker.
     *
     * The only place on the platform that can produce a worker's phone number,
     * and it does not produce it itself — the guard does, and writes down that
     * it did.
     */
    public function show(Request $request, WorkerProfile $worker): Response
    {
        Gate::authorize('view', $worker);

        /*
         * Never indexed. This page can carry a phone number, and a search
         * engine holding one is a disclosure outside every rate limit and log
         * the jobs module was built around. robots.txt says the same thing and
         * the sitemap leaves it out — this is the lock that works on a crawler
         * that arrived from a link somebody pasted.
         */
        app(Seo::class)->noindex();

        $worker->load('skills');

        $release = $this->guard->release($worker, $request->user(), $request);

        return Inertia::render('Jobs/Workers/Show', [
            'worker' => $worker->publicCard(),

            /*
             * Null unless the guard released it. The front end has no way to
             * reconstruct a number from what it is given, because what it is
             * given does not contain one.
             */
            'contact' => $release['contact'],
            'contactReason' => $release['reason'],
            'contactMessage' => $this->guard->explain($release['reason']),
            'remaining' => $release['remaining'],

            'ratings' => $this->approvedRatings($worker),
            'isSelf' => $worker->belongsToUser($request->user()),
            'notice' => JobBoardController::notice(),
        ]);
    }

    /**
     * The employer's own open listings, for the "rank against" picker.
     *
     * @return array<int, array{slug: string, title: string}>
     */
    private function employerListings(Request $request): array
    {
        $employer = $request->user()?->employerProfile;

        if ($employer === null) {
            return [];
        }

        return $employer->listings()
            ->onBoard()
            ->get()
            ->map(fn (JobListing $listing): array => [
                'slug' => $listing->slug,
                'title' => $listing->title,
            ])
            ->all();
    }

    /**
     * The listing to rank against, if the viewer actually owns it.
     *
     * Checked rather than trusted: ranking against somebody else's listing
     * would leak which skills a competitor is hiring for.
     */
    private function listingFor(Request $request, ?string $slug): ?JobListing
    {
        if ($slug === null) {
            return null;
        }

        $employer = $request->user()?->employerProfile;

        if ($employer === null) {
            return null;
        }

        return $employer->listings()->where('slug', $slug)->with('skills')->first();
    }

    /**
     * What people who hired this worker said, once an administrator let it
     * through.
     *
     * @return array<int, array<string, mixed>>
     */
    private function approvedRatings(WorkerProfile $worker): array
    {
        return JobRating::query()
            ->approved()
            ->where('rated_by', RatingParty::Employer)
            ->whereHas('application', fn ($q) => $q->where('worker_profile_id', $worker->getKey()))
            ->with('application.listing.employer')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (JobRating $rating): array => [
                'rating' => $rating->rating,
                'comment' => $rating->comment,
                'by' => $rating->application?->listing?->employer?->business_name,
                'job' => $rating->application?->listing?->title,
                'when' => $rating->created_at?->format('M Y'),
            ])
            ->all();
    }
}
