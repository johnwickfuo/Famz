<?php

namespace App\Services\Jobs;

use App\Enums\WorkTypeWanted;
use App\Models\JobListing;
use App\Models\WorkerProfile;
use Illuminate\Support\Collection;

/**
 * Ranking workers against a job, and jobs against a worker.
 *
 * Deliberately arithmetic, not AI. The inputs are a handful of tags, two
 * places, a work-type preference and a pay range — there is nothing here for a
 * language model to understand that a weighted sum does not already capture,
 * and a farmer on a bad connection should not wait on an API call to see who is
 * available in their state.
 *
 * Being deterministic also means the ranking can be explained. When somebody
 * asks why a candidate is third, `explain()` answers, which is not a thing an
 * embedding can do.
 *
 * The two directions share one score on purpose. A worker and a job that suit
 * each other should agree about it whichever side is looking — asymmetric
 * scoring would put a job at the top of a worker's board while keeping that
 * worker off the employer's shortlist, and neither party would understand why.
 */
class JobMatcher
{
    /**
     * What each factor is worth.
     *
     * Skills dominate because they are the only thing that decides whether
     * somebody can do the work at all. Location is next, because a farm job is
     * physical and daily. Pay and work type are qualifiers rather than drivers:
     * they mostly serve to push a bad fit down rather than to lift a good one.
     */
    private const WEIGHT_SKILLS = 55;

    private const WEIGHT_LOCATION = 25;

    private const WEIGHT_WORK_TYPE = 10;

    private const WEIGHT_PAY = 10;

    /**
     * Workers who might suit this job, best first.
     *
     * @param  Collection<int, WorkerProfile>  $workers
     * @return Collection<int, array{worker: WorkerProfile, score: int, reasons: array<int, string>}>
     */
    public function rankWorkersFor(JobListing $listing, Collection $workers): Collection
    {
        return $workers
            ->map(fn (WorkerProfile $worker): array => [
                'worker' => $worker,
                ...$this->score($listing, $worker),
            ])
            ->sortByDesc(fn (array $row): int => $row['score'])
            ->values();
    }

    /**
     * Jobs that might suit this worker, best first.
     *
     * @param  Collection<int, JobListing>  $listings
     * @return Collection<int, array{listing: JobListing, score: int, reasons: array<int, string>}>
     */
    public function rankListingsFor(WorkerProfile $worker, Collection $listings): Collection
    {
        return $listings
            ->map(fn (JobListing $listing): array => [
                'listing' => $listing,
                ...$this->score($listing, $worker),
            ])
            ->sortByDesc(fn (array $row): int => $row['score'])
            ->values();
    }

    /**
     * The one score, out of 100, with the reasons it came out that way.
     *
     * @return array{score: int, reasons: array<int, string>}
     */
    public function score(JobListing $listing, WorkerProfile $worker): array
    {
        $reasons = [];

        // --- Skills -------------------------------------------------------
        $wanted = $listing->skills->pluck('id');
        $has = $worker->skills->pluck('id');

        $overlap = $wanted->isEmpty() ? collect() : $wanted->intersect($has);

        /*
         * A listing that names no skills is asking for general farm labour,
         * and everybody is a partial match for it — scored at half rather than
         * full, so a listing that does name skills always outranks it for
         * somebody who has them.
         */
        $skillShare = $wanted->isEmpty() ? 0.5 : $overlap->count() / $wanted->count();
        $skillPoints = (int) round(self::WEIGHT_SKILLS * $skillShare);

        if ($overlap->isNotEmpty()) {
            $names = $listing->skills
                ->whereIn('id', $overlap->all())
                ->pluck('name')
                ->take(3)
                ->implode(', ');

            $reasons[] = trans_choice(
                'Has :names|Has :names and :count others',
                max(0, $overlap->count() - 3),
                ['names' => $names, 'count' => $overlap->count() - 3],
            );
        }

        // --- Location -----------------------------------------------------
        $sameState = $this->same($listing->state, $worker->state);
        $sameLga = $sameState && $this->same($listing->lga, $worker->lga);

        $locationPoints = match (true) {
            $sameLga => self::WEIGHT_LOCATION,
            $sameState => (int) round(self::WEIGHT_LOCATION * 0.8),
            /*
             * Somebody willing to move is a real candidate anywhere, but not as
             * strong a one as somebody already there: relocating for farm work
             * is a decision that falls through often, and a shortlist that
             * ignored that would waste an employer's week.
             */
            $worker->willing_to_relocate => (int) round(self::WEIGHT_LOCATION * 0.45),
            default => 0,
        };

        if ($sameLga) {
            $reasons[] = __('Lives in :place', ['place' => $listing->lga]);
        } elseif ($sameState) {
            $reasons[] = __('In :state', ['state' => $listing->state]);
        } elseif ($worker->willing_to_relocate) {
            $reasons[] = __('Willing to relocate');
        }

        // --- Work type ----------------------------------------------------
        $typeMatches = $worker->work_type_wanted->accepts($listing->job_type);
        $workTypePoints = $typeMatches ? self::WEIGHT_WORK_TYPE : 0;

        if ($typeMatches && $worker->work_type_wanted !== WorkTypeWanted::Both) {
            $reasons[] = __('Wants :type work', ['type' => mb_strtolower($listing->job_type->label())]);
        }

        // --- Pay ----------------------------------------------------------
        [$payPoints, $payReason] = $this->scorePay($listing, $worker);

        if ($payReason !== null) {
            $reasons[] = $payReason;
        }

        return [
            'score' => $skillPoints + $locationPoints + $workTypePoints + $payPoints,
            'reasons' => $reasons,
        ];
    }

    /**
     * Do the money expectations overlap?
     *
     * Both sides are normalised to a month first, because a daily rate and a
     * monthly salary cannot be compared otherwise. The normalisation is rough
     * and is used only to rank — never to quote anybody a figure.
     *
     * Silence scores neutral rather than zero. Plenty of listings say "pay
     * negotiable" and plenty of workers have no fixed number, and punishing
     * either for not guessing would push the most flexible people to the bottom
     * of the list.
     *
     * @return array{0: int, 1: string|null}
     */
    private function scorePay(JobListing $listing, WorkerProfile $worker): array
    {
        $offer = $listing->monthlyPay();
        $want = $worker->monthlyExpectation();

        $offerTop = $offer['max'] ?? $offer['min'];
        $wantFloor = $want['min'] ?? $want['max'];

        if ($offerTop === null || $wantFloor === null) {
            return [(int) round(self::WEIGHT_PAY * 0.5), null];
        }

        if ($offerTop >= $wantFloor) {
            return [self::WEIGHT_PAY, __('Pay is in range')];
        }

        /*
         * The offer is below what they asked for. Scored on how far below
         * rather than to zero: somebody asking for 60,000 will often take
         * 55,000, and will almost never take 20,000. Falls to nothing once the
         * offer is half of what was asked.
         */
        $ratio = $offerTop / max(1, $wantFloor);
        $points = (int) round(self::WEIGHT_PAY * max(0, ($ratio - 0.5) * 2));

        return [$points, $points > 0 ? __('Pay is a little under what they asked') : null];
    }

    /**
     * Case- and whitespace-insensitive comparison of two place names.
     *
     * State and LGA are free text typed by two different people, so "Oyo " and
     * "oyo" have to count as the same place or the location score never fires.
     */
    private function same(?string $a, ?string $b): bool
    {
        if (blank($a) || blank($b)) {
            return false;
        }

        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * A one-line summary of why something ranked where it did.
     *
     * @param  array<int, string>  $reasons
     */
    public function explain(array $reasons): ?string
    {
        return $reasons === [] ? null : implode(' · ', $reasons);
    }
}
