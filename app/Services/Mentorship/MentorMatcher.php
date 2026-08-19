<?php

namespace App\Services\Mentorship;

use App\Models\MentorProfile;
use App\Models\MentorshipMatch;
use App\Models\MentorshipPackage;
use App\Models\Specialisation;
use Illuminate\Support\Collection;

/**
 * Ranking mentors against what somebody needs.
 *
 * Five things decide the order, weighted so that the ones a client can judge
 * for themselves matter less than the ones they cannot:
 *
 *   tag overlap  — what they actually need help with. The heaviest by far.
 *   budget fit   — a mentor whose cheapest package is out of reach is no use,
 *                  however good the overlap.
 *   location     — only when the client asked to meet in person.
 *   rating       — approved reviews only, and worth little until there are a
 *                  few of them.
 *   experience   — completed engagements, on a curve that flattens quickly.
 *
 * The last two are kept deliberately light. Weighting them heavily is how a
 * marketplace ends up with five mentors taking all the work and everybody else
 * never getting a first engagement, which is the failure mode this ranking is
 * shaped to avoid.
 */
class MentorMatcher
{
    public const WEIGHT_TAGS = 60;

    public const WEIGHT_BUDGET = 20;

    public const WEIGHT_LOCATION = 10;

    public const WEIGHT_RATING = 7;

    public const WEIGHT_EXPERIENCE = 3;

    public function __construct(private readonly SpecialisationMatcher $specialisations) {}

    /**
     * Run a match and write it down.
     */
    public function run(MatchRequest $request, int $limit = 12): MentorshipMatch
    {
        $resolved = $this->specialisations->resolve($request);

        $ranked = $this->rank($request, $resolved['ids'], $limit);

        $match = new MentorshipMatch;

        $match->forceFill([
            'user_id' => $request->user?->getKey(),
            'session_token' => $request->sessionToken,
            'need_description' => $request->need,
            'sector' => $request->sector,
            'preferred_state' => $request->state,
            'wants_remote' => $request->wantsRemote,
            'wants_in_person' => $request->wantsInPerson,
            'budget_min_kobo' => $request->budgetMinKobo,
            'budget_max_kobo' => $request->budgetMaxKobo,
            'matched_specialisation_ids' => $resolved['ids'],
            'resolver' => $resolved['resolver'],
            'resolver_note' => $resolved['note'],
            'results' => $ranked->all(),
            'results_count' => $ranked->count(),
        ])->save();

        return $match->refresh();
    }

    /**
     * The shortlist itself.
     *
     * @param  array<int, int>  $specialisationIds
     * @return Collection<int, array<string, mixed>>
     */
    public function rank(MatchRequest $request, array $specialisationIds, int $limit = 12): Collection
    {
        $mentors = MentorProfile::query()
            ->bookable()
            ->with(['user', 'specialisations', 'packages' => fn ($q) => $q->where('is_active', true)])
            // A mentor with nothing to sell cannot be hired, so showing them is
            // a dead end dressed up as a result.
            ->whereHas('packages', fn ($q) => $q->where('is_active', true))
            ->get();

        return $mentors
            ->map(fn (MentorProfile $mentor): array => $this->score($mentor, $request, $specialisationIds))
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * @param  array<int, int>  $wanted
     * @return array<string, mixed>
     */
    private function score(MentorProfile $mentor, MatchRequest $request, array $wanted): array
    {
        $mentorTags = $mentor->specialisations->pluck('id')->all();
        $overlap = array_values(array_intersect($wanted, $mentorTags));

        /*
         * With no tags to go on — the AI declined and no keyword matched
         * anything — everybody scores the same on relevance rather than
         * everybody scoring zero. A shortlist ordered by rating alone is a
         * worse answer than no shortlist only if it pretends to be relevant,
         * and the page says plainly which it is.
         */
        $tagScore = $wanted === []
            ? self::WEIGHT_TAGS * 0.4
            : self::WEIGHT_TAGS * (count($overlap) / max(1, count($wanted)));

        $cheapest = $mentor->packages->min('price_kobo');
        $affordable = $cheapest !== null && $request->affords((int) $cheapest);

        $budgetScore = match (true) {
            ! $request->hasBudget() => self::WEIGHT_BUDGET * 0.5,
            $affordable => self::WEIGHT_BUDGET,
            default => 0.0,
        };

        // Only asked when it was asked for. Somebody happy on WhatsApp does not
        // care which state their mentor sleeps in.
        $locationScore = match (true) {
            ! $request->wantsInPerson => self::WEIGHT_LOCATION * 0.5,
            $mentor->accepts_in_person && $mentor->servesState($request->state) => self::WEIGHT_LOCATION,
            default => 0.0,
        };

        // Half marks with no reviews yet, so a new mentor is not buried under
        // somebody with a single five-star review from their cousin.
        $ratingScore = $mentor->average_rating === null
            ? self::WEIGHT_RATING * 0.5
            : self::WEIGHT_RATING * ($mentor->average_rating / 5);

        // Flattens fast: the difference between 0 and 5 engagements matters,
        // the difference between 40 and 80 does not.
        $experienceScore = self::WEIGHT_EXPERIENCE * min(1, $mentor->engagements_completed / 10);

        $score = $tagScore + $budgetScore + $locationScore + $ratingScore + $experienceScore;

        // A hard exclusion rather than a low score: somebody who can only meet
        // in person is not served at all by a remote-only mentor.
        if ($request->wantsInPerson && ! $request->wantsRemote && ! $mentor->accepts_in_person) {
            $score = 0;
        }

        return [
            'mentor_profile_id' => $mentor->id,
            'slug' => $mentor->slug,
            'score' => round($score, 2),
            'matched_specialisation_ids' => $overlap,
            'affordable' => $affordable,
            'cheapest_kobo' => $cheapest === null ? null : (int) $cheapest,
            'breakdown' => [
                'tags' => round($tagScore, 2),
                'budget' => round($budgetScore, 2),
                'location' => round($locationScore, 2),
                'rating' => round($ratingScore, 2),
                'experience' => round($experienceScore, 2),
            ],
        ];
    }

    /**
     * Turn a stored run back into something a page can render.
     *
     * Read fresh from the mentors rather than from the stored rows: the score
     * is history, but a price or a rating shown to somebody now has to be
     * current.
     *
     * @return array<int, array<string, mixed>>
     */
    public function present(MentorshipMatch $match): array
    {
        $rows = collect($match->results ?? []);

        $mentors = MentorProfile::query()
            ->whereIn('id', $rows->pluck('mentor_profile_id'))
            ->with(['user', 'specialisations', 'packages' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->keyBy('id');

        $wanted = Specialisation::query()
            ->whereIn('id', $match->matched_specialisation_ids ?? [])
            ->pluck('name', 'id');

        return $rows
            ->map(function (array $row) use ($mentors, $wanted): ?array {
                $mentor = $mentors->get($row['mentor_profile_id']);

                // A mentor suspended since the run simply drops out.
                if ($mentor === null || ! $mentor->isBookable()) {
                    return null;
                }

                return [
                    ...$mentor->publicCard(),
                    'score' => $row['score'],
                    'affordable' => $row['affordable'] ?? true,
                    'matched' => collect($row['matched_specialisation_ids'] ?? [])
                        ->map(fn (int $id): ?string => $wanted->get($id))
                        ->filter()
                        ->values()
                        ->all(),
                    'packages' => $mentor->packages
                        ->map(fn (MentorshipPackage $package): array => $package->card())
                        ->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
