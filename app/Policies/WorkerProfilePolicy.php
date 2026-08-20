<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;
use App\Models\WorkerProfile;

/**
 * Who may look at a worker, and who may edit one.
 *
 * Viewing the profile is not the same question as seeing the phone number —
 * that one is answered by WorkerContactGuard, which also counts and records the
 * answer. This policy governs the page; the guard governs the number.
 */
class WorkerProfilePolicy
{
    /**
     * The directory is for employers.
     *
     * Not because a worker's name is secret, but because a public, crawlable
     * index of people looking for work is the raw material for exactly the
     * harvesting this module exists to prevent — and because the page is only
     * useful to somebody hiring.
     */
    public function viewAny(?User $user): bool
    {
        return $user !== null
            && ($user->holdsRole(RoleName::Employer) || $user->holdsRole(RoleName::Admin));
    }

    public function view(?User $user, WorkerProfile $profile): bool
    {
        if (! $profile->is_active) {
            return $user !== null
                && ($profile->belongsToUser($user) || $user->holdsRole(RoleName::Admin));
        }

        return $this->viewAny($user) || ($user !== null && $profile->belongsToUser($user));
    }

    public function update(User $user, WorkerProfile $profile): bool
    {
        return $profile->belongsToUser($user) || $user->holdsRole(RoleName::Admin);
    }
}
