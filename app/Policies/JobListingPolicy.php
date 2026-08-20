<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\JobListing;
use App\Models\User;

/**
 * Who may see and manage a listing.
 *
 * A draft belongs to its employer alone; everything else is public, because a
 * jobs board that hid its jobs would be no use to the people it exists for.
 */
class JobListingPolicy
{
    public function view(?User $user, JobListing $listing): bool
    {
        if ($listing->status->isPublic()) {
            return true;
        }

        return $user !== null && ($this->owns($user, $listing) || $user->holdsRole(RoleName::Admin));
    }

    public function update(User $user, JobListing $listing): bool
    {
        return $this->owns($user, $listing) || $user->holdsRole(RoleName::Admin);
    }

    public function delete(User $user, JobListing $listing): bool
    {
        return $this->update($user, $listing);
    }

    /**
     * Who sees the applicants — and, with them, workers' phone numbers.
     *
     * The employer who posted it, and nobody else short of an administrator.
     * An applicant list is a set of numbers from people who chose to give them
     * to one farm, not to the board.
     */
    public function viewApplicants(User $user, JobListing $listing): bool
    {
        return $this->owns($user, $listing) || $user->holdsRole(RoleName::Admin);
    }

    /**
     * Who may apply.
     *
     * A worker with a profile, on an open listing, who does not own it. The
     * last clause is not paranoia: somebody can hold both roles here, and an
     * employer applying to their own job would be a hire nobody could dispute.
     */
    public function apply(User $user, JobListing $listing): bool
    {
        if (! $listing->acceptsApplications()) {
            return false;
        }

        if ($this->owns($user, $listing)) {
            return false;
        }

        return $user->holdsRole(RoleName::Worker) && $user->workerProfile !== null;
    }

    private function owns(User $user, JobListing $listing): bool
    {
        return $listing->employer?->user_id === $user->getKey();
    }
}
