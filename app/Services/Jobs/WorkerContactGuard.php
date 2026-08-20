<?php

namespace App\Services\Jobs;

use App\Enums\RoleName;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Models\WorkerProfileView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Who gets a worker's phone number, how often, and on what record.
 *
 * A farm worker's phone number is frequently the only asset they have in a job
 * search. Handed out freely it becomes a call list: for recruiters who charge
 * for placements the platform does not endorse, for anybody selling something,
 * and — since these are people who will travel to an unfamiliar village on the
 * strength of a phone call — for worse. So the number is released under three
 * conditions at once, and all three are checked here rather than in a template:
 *
 *   1. The viewer is authenticated and holds the employer role.
 *   2. They have not already exhausted a day's allowance.
 *   3. The release is written down before it happens.
 *
 * Nothing in this class returns a number without recording that it did.
 */
class WorkerContactGuard
{
    /**
     * How many workers' numbers one account may see in a day.
     *
     * Generous for an employer filling one job — they might genuinely look at
     * twenty candidates in an afternoon — and useless for building a call list
     * of every worker in a state. The limit is per account and per calendar
     * day, and it counts only the views that actually released a number: a
     * repeat visit to the same worker is not a second disclosure.
     */
    public const DEFAULT_DAILY_LIMIT = 25;

    public function dailyLimit(): int
    {
        return max(1, (int) settings('worker_contact_daily_limit', self::DEFAULT_DAILY_LIMIT));
    }

    /**
     * How many distinct workers this account has been given today.
     */
    public function usedToday(User $viewer): int
    {
        return WorkerProfileView::query()
            ->where('user_id', $viewer->getKey())
            ->releasing()
            ->since(now()->startOfDay())
            ->distinct()
            ->count('worker_profile_id');
    }

    public function remainingToday(User $viewer): int
    {
        return max(0, $this->dailyLimit() - $this->usedToday($viewer));
    }

    /**
     * Whether this account has already been given this worker today.
     *
     * Coming back to a profile you were legitimately shown an hour ago is
     * normal — an employer comparing three candidates will do it — and charging
     * for it again would burn the allowance on ordinary use.
     */
    public function alreadySeenToday(User $viewer, WorkerProfile $worker): bool
    {
        return WorkerProfileView::query()
            ->where('user_id', $viewer->getKey())
            ->where('worker_profile_id', $worker->getKey())
            ->releasing()
            ->since(now()->startOfDay())
            ->exists();
    }

    /**
     * Record the view, and decide whether the number goes with it.
     *
     * Every visit to a worker's profile is logged whether or not anything is
     * released, because a run of look-but-no-contact requests is itself a
     * pattern worth being able to see.
     *
     * @return array{contact: array{phone: string, whatsapp: string|null}|null, reason: string, remaining: int|null}
     */
    public function release(WorkerProfile $worker, ?User $viewer, ?Request $request = null): array
    {
        // A worker looking at their own profile is not a disclosure to anybody,
        // and must not burn an allowance or leave a harvesting-shaped trail.
        if ($viewer !== null && $worker->belongsToUser($viewer)) {
            return [
                'contact' => $worker->contactFor($viewer),
                'reason' => 'self',
                'remaining' => null,
            ];
        }

        $employerProfile = $viewer?->employerProfile;

        $eligible = $viewer !== null && $viewer->holdsRole(RoleName::Employer);

        // Repeat visits do not spend anything, so they are decided before the
        // limit is consulted.
        $repeat = $eligible && $this->alreadySeenToday($viewer, $worker);

        $withinLimit = $repeat || ($eligible && $this->remainingToday($viewer) > 0);

        $released = $eligible && $withinLimit;

        $this->log($worker, $viewer, $employerProfile, $released, $request);

        return [
            'contact' => $released ? $worker->contactFor($viewer) : null,
            'reason' => match (true) {
                ! $eligible => $viewer === null ? 'anonymous' : 'not_an_employer',
                ! $withinLimit => 'daily_limit',
                default => 'released',
            },
            'remaining' => $eligible ? $this->remainingToday($viewer) : null,
        ];
    }

    /**
     * Write the view down.
     *
     * Outside a transaction and deliberately best-effort in ordering: the log
     * is evidence, not a lock. What matters is that a release is never returned
     * without a row existing for it.
     */
    private function log(
        WorkerProfile $worker,
        ?User $viewer,
        mixed $employerProfile,
        bool $released,
        ?Request $request,
    ): void {
        DB::table('worker_profile_views')->insert([
            'worker_profile_id' => $worker->getKey(),
            'employer_profile_id' => $employerProfile?->getKey(),
            'user_id' => $viewer?->getKey(),
            'contact_released' => $released,
            'ip_address' => $request?->ip(),
            // Truncated: a user agent is a fingerprint, not an essay, and the
            // column is not the place to store somebody's whole browser build.
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 512),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * What to tell somebody who did not get the number.
     */
    public function explain(string $reason): ?string
    {
        return match ($reason) {
            'anonymous' => __('Phone numbers are only shown to registered employers. Workers here are trusting us with the one number they have, so we do not put it on a public page.'),
            'not_an_employer' => __('Only accounts registered as employers can see a worker\'s phone number. Add the employer role to your account if you are hiring.'),
            'daily_limit' => __('You have seen as many workers\' numbers as we release in a day. This resets tomorrow. If you genuinely need more, get in touch and tell us what you are hiring for.'),
            default => null,
        };
    }
}
