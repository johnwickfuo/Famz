<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\JobType;
use App\Http\Controllers\Controller;
use App\Models\JobListing;
use App\Models\WorkerSkill;
use App\Services\Jobs\JobMatcher;
use App\Support\Money;
use App\Support\Nigeria;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public job board.
 *
 * Free, open, and the platform is not the employer. Every page rendered from
 * here carries the notice saying so, because the alternative is somebody
 * turning up at a farm believing the company vouched for it.
 *
 * Nothing on this controller ever touches a worker's contact details. Listings
 * are employer-side records; the worker side of this board is behind
 * WorkerDirectoryController, which is where the phone number rule lives.
 */
class JobBoardController extends Controller
{
    public function __construct(private readonly JobMatcher $matcher) {}

    /**
     * The board, filtered.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'state' => ['nullable', 'string', 'max:64'],
            'job_type' => ['nullable', 'string', 'in:'.implode(',', JobType::values())],
            'skill' => ['nullable', 'integer', 'exists:worker_skills,id'],
            'pay_min' => ['nullable', 'numeric', 'min:0'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = JobListing::query()
            ->onBoard()
            ->with(['employer', 'skills'])
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('state', $state))
            ->when($filters['job_type'] ?? null, fn ($q, $type) => $q->where('job_type', $type))
            ->when($filters['skill'] ?? null, fn ($q, $skill) => $q->whereHas(
                'skills',
                fn ($s) => $s->where('worker_skills.id', $skill),
            ))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($w) => $w->where('title', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"),
            ));

        /*
         * A pay filter has to compare like with like. A worker asking for
         * 50,000 a month should not have a 3,000-a-day job hidden from them
         * because the raw column is smaller — so the comparison happens on the
         * monthly-normalised figure, in PHP, after the query.
         */
        $payFloorKobo = isset($filters['pay_min']) ? Money::toKobo($filters['pay_min']) : null;

        $listings = $query->latest('published_at')->get();

        if ($payFloorKobo !== null) {
            $listings = $listings->filter(function (JobListing $listing) use ($payFloorKobo): bool {
                $top = $listing->monthlyPay()['max'] ?? $listing->monthlyPay()['min'];

                // A listing that quotes no pay stays in: "negotiable" is not
                // the same as "pays nothing", and hiding it would punish
                // employers for being honest that it is open to discussion.
                return $top === null || $top >= $payFloorKobo;
            })->values();
        }

        $worker = $request->user()?->workerProfile;

        /*
         * A signed-in worker sees the same board, ranked for them. Not a
         * different board — filtering somebody out of jobs they might want is
         * not the platform's decision to make — just a better order.
         */
        if ($worker !== null) {
            $worker->loadMissing('skills');

            $ranked = $this->matcher->rankListingsFor($worker, $listings);

            $cards = $ranked->map(fn (array $row): array => [
                ...$row['listing']->publicCard(),
                'match_score' => $row['score'],
                'match_reason' => $this->matcher->explain($row['reasons']),
            ])->all();
        } else {
            $cards = $listings->map(fn (JobListing $listing): array => $listing->publicCard())->all();
        }

        return Inertia::render('Jobs/Board', [
            'listings' => $cards,
            'filters' => $filters,
            'states' => Nigeria::states(),
            'jobTypes' => JobType::options(),
            'skills' => WorkerSkill::grouped(),
            'isRanked' => $worker !== null,
            'hasWorkerProfile' => $worker !== null,
            'notice' => $this->notice(),
        ]);
    }

    /**
     * One job.
     */
    public function show(Request $request, JobListing $listing): Response
    {
        abort_unless($request->user()?->can('view', $listing) ?? $listing->status->isPublic(), 404);

        $listing->load(['employer', 'skills']);

        // Counted with an atomic increment rather than a read-modify-write, so
        // two people opening it at once do not lose one of the views.
        $listing->newQuery()->whereKey($listing->getKey())->increment('views_count');

        $user = $request->user();
        $worker = $user?->workerProfile;

        $existing = $worker === null
            ? null
            : $listing->applications()->where('worker_profile_id', $worker->getKey())->first();

        return Inertia::render('Jobs/Show', [
            'listing' => [
                ...$listing->publicCard(),
                'description' => $listing->description,
                'start_date' => $listing->start_date?->format('j F Y'),
                'pay_period' => $listing->pay_period->noun(),
                'views' => $listing->views_count + 1,
            ],
            // The employer's own details are public by design: somebody
            // advertising for staff is inviting contact.
            'employer' => $listing->employer?->publicCard(),
            'application' => $existing === null ? null : [
                'status' => $existing->status->value,
                'status_label' => $existing->status->workerLabel(),
                'applied_at' => $existing->applied_at?->format('j M Y'),
            ],
            'canApply' => $user !== null && $user->can('apply', $listing),
            'needsWorkerProfile' => $user !== null && $worker === null,
            'notice' => $this->notice(),
        ]);
    }

    /**
     * The notice that goes on every page of this module.
     *
     * Not a footnote. People will travel on the strength of these listings, and
     * the single most important thing the platform can tell them is that it has
     * not checked anybody and is not standing behind anything.
     *
     * @return array<string, string>
     */
    public static function notice(): array
    {
        return [
            'heading' => __('What this board is, and is not'),
            'body' => __('This board is free. We do not make placements, we do not handle wages, and we are not part of any agreement you reach. We do not check employers or workers. Meet in a public place first if you can, agree the pay and the hours before you start, and never pay anybody a fee to get a job here — nobody on this platform is entitled to charge you one.'),
        ];
    }
}
