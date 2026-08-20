<?php

namespace App\Services\Jobs;

use App\Enums\JobApplicationStatus;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applying for a job, and what an employer does with the applications.
 *
 * No money and no placement. The platform hands one person's message to
 * another and records what happened to it; everything after that is between the
 * two of them, and the notice on every page says so.
 */
class ApplicationService
{
    /**
     * Put a worker forward for a job.
     *
     * One per worker per listing, and the uniqueness is caught from the
     * database rather than checked first. Somebody double-tapping Apply on a
     * bad connection fires two identical requests milliseconds apart; a
     * check-then-insert would let both through, and the second would become a
     * duplicate applicant the employer has to work out how to ignore.
     */
    public function apply(JobListing $listing, WorkerProfile $worker, ?string $message = null): JobApplication
    {
        if (! $listing->acceptsApplications()) {
            throw new RuntimeException(__('This job is no longer taking applications.'));
        }

        try {
            return DB::transaction(function () use ($listing, $worker, $message): JobApplication {
                $application = new JobApplication(['cover_message' => trim((string) $message) ?: null]);

                $application->forceFill([
                    'job_listing_id' => $listing->getKey(),
                    'worker_profile_id' => $worker->getKey(),
                    'status' => JobApplicationStatus::Applied,
                    'applied_at' => now(),
                ])->save();

                return $application;
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) {
                throw new RuntimeException(__('You have already applied for this job.'));
            }

            throw $exception;
        }
    }

    /**
     * Move an application along.
     *
     * The status and who changed it are written together, because "I never
     * rejected them" is a thing somebody will say and a name against the change
     * is the only answer to it.
     */
    public function moveTo(
        JobApplication $application,
        JobApplicationStatus $status,
        User $actor,
    ): JobApplication {
        if ($application->status === $status) {
            return $application;
        }

        if ($application->status === JobApplicationStatus::Withdrawn) {
            throw new RuntimeException(__('The worker withdrew this application.'));
        }

        $application->forceFill([
            'status' => $status,
            'status_changed_at' => now(),
            'status_changed_by' => $actor->getKey(),
        ])->save();

        return $application->refresh();
    }

    /**
     * The worker changing their own mind.
     *
     * Kept apart from moveTo because only the worker may do it, and an employer
     * who could withdraw somebody's application would be rewriting a decision
     * that is not theirs to make.
     */
    public function withdraw(JobApplication $application, User $worker): JobApplication
    {
        if (! $application->worker?->belongsToUser($worker)) {
            throw new RuntimeException(__('This is not your application.'));
        }

        if ($application->status->isHired()) {
            throw new RuntimeException(__('You have already been hired for this one. Talk to the employer directly.'));
        }

        $application->forceFill([
            'status' => JobApplicationStatus::Withdrawn,
            'status_changed_at' => now(),
            'status_changed_by' => $worker->getKey(),
        ])->save();

        return $application->refresh();
    }

    /**
     * Mark an application as seen, once.
     *
     * Only moves an untouched application, so opening a shortlisted candidate's
     * details does not quietly demote them back to "seen".
     */
    public function markViewed(JobApplication $application, User $actor): JobApplication
    {
        if ($application->status !== JobApplicationStatus::Applied) {
            return $application;
        }

        return $this->moveTo($application, JobApplicationStatus::Viewed, $actor);
    }

    private function isDuplicate(QueryException $exception): bool
    {
        // 23000 covers the integrity-constraint family across MySQL and
        // SQLite, which is as portable as this check gets.
        return ($exception->errorInfo[0] ?? null) === '23000';
    }
}
