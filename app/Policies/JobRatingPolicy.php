<?php

namespace App\Policies;

use App\Enums\RatingParty;
use App\Enums\RoleName;
use App\Models\JobApplication;
use App\Models\JobRating;
use App\Models\User;

/**
 * Who may rate whom, and on what.
 *
 * The hire requirement lives here rather than in a controller or a form,
 * because a rating system nobody has to earn access to is a rating system that
 * gets abused — and on a board where the platform verifies nobody, a fabricated
 * one-star review is a cheap way to cost a competitor a season's work.
 *
 * Three conditions, all of them structural rather than cosmetic:
 *
 *   1. The application must be marked `hired`. Not applied, not shortlisted —
 *      somebody actually took the job.
 *   2. The rater must be a party to that specific application: the employer who
 *      posted the listing, or the worker who was hired onto it.
 *   3. They must not already have rated it. One per side, so neither can pile
 *      on, backed by a unique index in case two requests race.
 *
 * The direction is derived from which party the user is, never taken from the
 * request. Somebody who is an employer here and a worker elsewhere must not be
 * able to choose which hat they are wearing.
 */
class JobRatingPolicy
{
    /**
     * Whether this user may write a rating on this application at all.
     */
    public function create(User $user, JobApplication $application): bool
    {
        return $this->partyFor($user, $application) !== null;
    }

    /**
     * Which side of this application the user is, or null if neither.
     *
     * This is the whole gate. Everything else about the rating flow reads its
     * answer: the form is offered only when it returns a party, the rating is
     * stamped with the party it returns, and a user who is somehow both is
     * treated as the employer because that is the side that posted the job.
     */
    public function partyFor(User $user, JobApplication $application): ?RatingParty
    {
        // Condition one: it has to be a real hire. Everything below is moot
        // without it.
        if (! $application->isHired()) {
            return null;
        }

        $employerUser = $application->employerUser();
        $workerUser = $application->workerUser();

        $party = match (true) {
            $employerUser !== null && $employerUser->is($user) => RatingParty::Employer,
            $workerUser !== null && $workerUser->is($user) => RatingParty::Worker,
            default => null,
        };

        if ($party === null) {
            return null;
        }

        // Condition three: one per side.
        return $application->hasRatingFrom($party) ? null : $party;
    }

    /**
     * Ratings are read only once an administrator has let them through.
     */
    public function view(?User $user, JobRating $rating): bool
    {
        if ($rating->isPublished()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        // The author sees their own while it waits, so nobody thinks it was
        // swallowed, and administrators see everything.
        return $rating->author_id === $user->getKey() || $user->holdsRole(RoleName::Admin);
    }

    /**
     * Only an administrator publishes or rejects.
     */
    public function moderate(User $user, JobRating $rating): bool
    {
        return $user->holdsRole(RoleName::Admin);
    }
}
