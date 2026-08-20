<?php

namespace App\Services\Jobs;

use App\Enums\RatingParty;
use App\Enums\RatingStatus;
use App\Models\JobApplication;
use App\Models\JobRating;
use App\Models\User;
use App\Policies\JobRatingPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rating the other side of a hire.
 *
 * The gate is JobRatingPolicy and this class does not reimplement it — it asks.
 * Duplicating the hire requirement here would mean two places to keep in step
 * and one of them eventually drifting, which on a rating system is how a
 * fabricated review gets in.
 *
 * Nothing published without a person looking at it first. That costs immediacy
 * and buys the ability to take down a rating written in a temper; on a board
 * where a bad word can cost somebody a season's work, it is worth it.
 */
class RatingService
{
    public function __construct(private readonly JobRatingPolicy $policy) {}

    /**
     * Leave a rating on a hire.
     *
     * The party is derived from the policy's answer, never from the request.
     * Somebody who is an employer here and a worker elsewhere must not be able
     * to choose which hat they were wearing.
     */
    public function leave(
        JobApplication $application,
        User $author,
        int $rating,
        ?string $comment = null,
    ): JobRating {
        $party = $this->policy->partyFor($author, $application);

        if ($party === null) {
            throw new RuntimeException($this->refusalFor($application, $author));
        }

        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException(__('A rating runs from one to five.'));
        }

        try {
            return DB::transaction(function () use ($application, $author, $party, $rating, $comment): JobRating {
                $record = new JobRating([
                    'rating' => $rating,
                    'comment' => trim((string) $comment) ?: null,
                ]);

                $record->forceFill([
                    'job_application_id' => $application->getKey(),
                    'rated_by' => $party,
                    'author_id' => $author->getKey(),
                    'status' => RatingStatus::Pending,
                ])->save();

                return $record;
            });
        } catch (QueryException $exception) {
            // The unique index is the backstop for two requests racing past the
            // policy's "have they rated already" check at the same moment.
            if (($exception->errorInfo[0] ?? null) === '23000') {
                throw new RuntimeException(__('You have already rated this one.'));
            }

            throw $exception;
        }
    }

    /**
     * Publish or reject, and recompute whatever average it affects.
     */
    public function moderate(
        JobRating $rating,
        RatingStatus $status,
        User $admin,
        ?string $note = null,
    ): JobRating {
        if ($status === RatingStatus::Pending) {
            throw new RuntimeException(__('Moderating means publishing it or not.'));
        }

        DB::transaction(function () use ($rating, $status, $admin, $note): void {
            $rating->forceFill([
                'status' => $status,
                'moderated_by' => $admin->getKey(),
                'moderated_at' => now(),
                'moderation_note' => trim((string) $note) ?: null,
            ])->save();

            $this->recalculate($rating);
        });

        return $rating->refresh();
    }

    /**
     * Recompute the average on whichever profile this rating is about.
     *
     * From approved ratings only. A pending rating counts for nothing — if it
     * moved the average before a person had read it, moderation would be
     * decoration.
     */
    public function recalculate(JobRating $rating): void
    {
        $application = $rating->application;

        if ($application === null) {
            return;
        }

        $subject = $rating->subject();

        $profile = $subject === RatingParty::Worker
            ? $application->worker
            : $application->listing?->employer;

        if ($profile === null) {
            return;
        }

        // Every approved rating written BY the other side, across every
        // application this profile has been part of.
        $query = JobRating::query()
            ->approved()
            ->where('rated_by', $subject->subject())
            ->whereHas('application', function ($applications) use ($subject, $profile): void {
                if ($subject === RatingParty::Worker) {
                    $applications->where('worker_profile_id', $profile->getKey());

                    return;
                }

                $applications->whereHas(
                    'listing',
                    fn ($listings) => $listings->where('employer_profile_id', $profile->getKey()),
                );
            });

        $count = (clone $query)->count();
        $average = $count === 0 ? null : round((clone $query)->avg('rating'), 2);

        $profile->forceFill([
            'rating_average' => $average,
            'rating_count' => $count,
        ])->save();
    }

    /**
     * Why somebody was refused, in words that say what to do about it.
     */
    private function refusalFor(JobApplication $application, User $author): string
    {
        if (! $application->isHired()) {
            return __('Ratings are only for jobs somebody actually did. This application has not been marked as hired.');
        }

        $isParty = $application->employerUser()?->is($author) || $application->workerUser()?->is($author);

        if (! $isParty) {
            return __('Only the two people involved in this job can rate it.');
        }

        return __('You have already rated this one.');
    }
}
