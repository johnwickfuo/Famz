<?php

use App\Enums\JobApplicationStatus;
use App\Enums\JobListingStatus;
use App\Enums\JobType;
use App\Enums\RatingParty;
use App\Enums\RatingStatus;
use App\Enums\RoleName;
use App\Models\EmployerProfile;
use App\Models\JobListing;
use App\Models\JobRating;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\Jobs\ApplicationService;
use App\Services\Jobs\RatingService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * Applying, hiring, and earning the right to say something about it.
 *
 * The rule under test throughout is that a rating follows an actual hire.
 * On a board where the platform verifies nobody, a fabricated one-star review
 * is a cheap way to cost a competitor a season's work.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->applications = app(ApplicationService::class);
    $this->ratings = app(RatingService::class);

    $this->employerUser = User::factory()->create();
    $this->employerUser->assignRole(RoleName::Employer->value);
    $this->employer = EmployerProfile::factory()->create(['user_id' => $this->employerUser->id]);

    $this->workerUser = User::factory()->create();
    $this->workerUser->assignRole(RoleName::Worker->value);
    $this->worker = WorkerProfile::factory()->create(['user_id' => $this->workerUser->id]);

    $this->listing = JobListing::factory()->open()->create([
        'employer_profile_id' => $this->employer->id,
    ]);

    $this->hire = function () {
        $application = $this->applications->apply($this->listing, $this->worker, 'I can start Monday.');

        return $this->applications->moveTo($application, JobApplicationStatus::Hired, $this->employerUser);
    };
});

// ---------------------------------------------------------------------------
// Applying
// ---------------------------------------------------------------------------

it('takes an application', function () {
    $application = $this->applications->apply($this->listing, $this->worker, 'I can start Monday.');

    expect($application->status)->toBe(JobApplicationStatus::Applied)
        ->and($application->applied_at)->not->toBeNull()
        ->and($application->cover_message)->toContain('Monday');
});

it('refuses a second application from the same worker', function () {
    $this->applications->apply($this->listing, $this->worker);

    // Caught from the unique index rather than a check-then-insert, so a
    // double tap on a bad connection cannot become two applicants.
    expect(fn () => $this->applications->apply($this->listing, $this->worker))
        ->toThrow(RuntimeException::class, 'already applied');
});

it('refuses an application to a job that has closed', function () {
    $closed = JobListing::factory()->create([
        'employer_profile_id' => $this->employer->id,
        'status' => JobListingStatus::Closed,
    ]);

    expect(fn () => $this->applications->apply($closed, $this->worker))
        ->toThrow(RuntimeException::class, 'no longer taking applications');
});

it('refuses an application past the deadline even before the sweep runs', function () {
    $lapsed = JobListing::factory()->lapsed()->create(['employer_profile_id' => $this->employer->id]);

    // A page load can land between a deadline passing and the command noticing.
    expect(fn () => $this->applications->apply($lapsed, $this->worker))
        ->toThrow(RuntimeException::class, 'no longer taking applications');
});

it('records who moved an application and when', function () {
    $application = $this->applications->apply($this->listing, $this->worker);

    $moved = $this->applications->moveTo($application, JobApplicationStatus::Shortlisted, $this->employerUser);

    // "I never rejected them" is a thing somebody will say.
    expect($moved->status)->toBe(JobApplicationStatus::Shortlisted)
        ->and($moved->status_changed_by)->toBe($this->employerUser->id)
        ->and($moved->status_changed_at)->not->toBeNull();
});

it('does not demote a shortlisted applicant back to seen', function () {
    $application = $this->applications->apply($this->listing, $this->worker);
    $this->applications->moveTo($application, JobApplicationStatus::Shortlisted, $this->employerUser);

    $this->applications->markViewed($application->fresh(), $this->employerUser);

    expect($application->fresh()->status)->toBe(JobApplicationStatus::Shortlisted);
});

it('lets only the worker withdraw', function () {
    $application = $this->applications->apply($this->listing, $this->worker);

    expect(fn () => $this->applications->withdraw($application, $this->employerUser))
        ->toThrow(RuntimeException::class, 'not your application');

    $withdrawn = $this->applications->withdraw($application, $this->workerUser);

    expect($withdrawn->status)->toBe(JobApplicationStatus::Withdrawn);
});

it('stops an employer overwriting a withdrawal', function () {
    $application = $this->applications->apply($this->listing, $this->worker);
    $this->applications->withdraw($application, $this->workerUser);

    expect(fn () => $this->applications->moveTo(
        $application->fresh(),
        JobApplicationStatus::Hired,
        $this->employerUser,
    ))->toThrow(RuntimeException::class, 'withdrew');
});

// ---------------------------------------------------------------------------
// The hire gate on ratings
// ---------------------------------------------------------------------------

it('refuses a rating on an application nobody was hired for', function () {
    $application = $this->applications->apply($this->listing, $this->worker);

    expect(fn () => $this->ratings->leave($application, $this->employerUser, 5, 'Great.'))
        ->toThrow(RuntimeException::class, 'not been marked as hired');
});

it('refuses a rating on a shortlisted application', function () {
    $application = $this->applications->apply($this->listing, $this->worker);
    $this->applications->moveTo($application, JobApplicationStatus::Shortlisted, $this->employerUser);

    expect(fn () => $this->ratings->leave($application->fresh(), $this->employerUser, 1, 'Terrible.'))
        ->toThrow(RuntimeException::class, 'not been marked as hired');
});

it('allows a rating once somebody was actually hired', function () {
    $hired = ($this->hire)();

    $rating = $this->ratings->leave($hired, $this->employerUser, 5, 'Turned up every day.');

    expect($rating->rated_by)->toBe(RatingParty::Employer)
        ->and($rating->author_id)->toBe($this->employerUser->id)
        // Nothing is visible until somebody has read it.
        ->and($rating->status)->toBe(RatingStatus::Pending);
});

it('lets the worker rate the employer on the same hire', function () {
    $hired = ($this->hire)();

    $rating = $this->ratings->leave($hired, $this->workerUser, 4, 'Paid on time.');

    expect($rating->rated_by)->toBe(RatingParty::Worker);
});

it('refuses a rating from somebody who was not part of the job', function () {
    $hired = ($this->hire)();

    $stranger = User::factory()->create();
    $stranger->assignRole(RoleName::Employer->value);

    expect(fn () => $this->ratings->leave($hired, $stranger, 1, 'Never met them.'))
        ->toThrow(RuntimeException::class, 'Only the two people involved');
});

it('allows one rating per side and no more', function () {
    $hired = ($this->hire)();

    $this->ratings->leave($hired, $this->employerUser, 5, 'Good.');

    expect(fn () => $this->ratings->leave($hired->fresh(), $this->employerUser, 1, 'Changed my mind.'))
        ->toThrow(RuntimeException::class, 'already rated');
});

it('derives the side from the policy rather than from the request', function () {
    $hired = ($this->hire)();

    // Somebody who is an employer here and a worker elsewhere must not be able
    // to choose which hat they were wearing.
    $policy = app(App\Policies\JobRatingPolicy::class);

    expect($policy->partyFor($this->employerUser, $hired))->toBe(RatingParty::Employer)
        ->and($policy->partyFor($this->workerUser, $hired))->toBe(RatingParty::Worker);
});

it('refuses a rating outside one to five', function () {
    $hired = ($this->hire)();

    expect(fn () => $this->ratings->leave($hired, $this->employerUser, 6))
        ->toThrow(RuntimeException::class, 'one to five');
});

// ---------------------------------------------------------------------------
// Moderation
// ---------------------------------------------------------------------------

it('leaves the average alone until a rating is approved', function () {
    $hired = ($this->hire)();
    $this->ratings->leave($hired, $this->employerUser, 5, 'Good.');

    // If a pending rating moved the average, moderation would be decoration.
    expect($this->worker->fresh()->rating_count)->toBe(0)
        ->and($this->worker->fresh()->rating_average)->toBeNull();
});

it('recomputes the worker average once approved', function () {
    $hired = ($this->hire)();
    $rating = $this->ratings->leave($hired, $this->employerUser, 4, 'Solid.');

    $admin = User::factory()->create();
    $admin->assignRole(RoleName::Admin->value);

    $this->ratings->moderate($rating, RatingStatus::Approved, $admin, 'Reads fine.');

    expect($this->worker->fresh()->rating_count)->toBe(1)
        ->and((float) $this->worker->fresh()->rating_average)->toBe(4.0);
});

it('keeps a rejected rating out of the average', function () {
    $hired = ($this->hire)();
    $rating = $this->ratings->leave($hired, $this->employerUser, 1, 'Abusive nonsense.');

    $admin = User::factory()->create();
    $admin->assignRole(RoleName::Admin->value);

    $this->ratings->moderate($rating, RatingStatus::Rejected, $admin, 'Personal abuse.');

    expect($this->worker->fresh()->rating_count)->toBe(0)
        ->and(JobRating::query()->approved()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Expiry
// ---------------------------------------------------------------------------

it('expires listings past their deadline and no others', function () {
    $lapsed = JobListing::factory()->lapsed()->create(['employer_profile_id' => $this->employer->id]);
    $live = JobListing::factory()->open()->create(['employer_profile_id' => $this->employer->id]);

    $this->artisan('jobs:expire')->assertSuccessful();

    expect($lapsed->fresh()->status)->toBe(JobListingStatus::Expired)
        ->and($live->fresh()->status)->toBe(JobListingStatus::Open);
});

it('leaves a listing alone on its last valid day', function () {
    $today = JobListing::factory()->open()->create([
        'employer_profile_id' => $this->employer->id,
        'application_deadline' => now()->toDateString(),
    ]);

    // A deadline of the 30th means the 30th.
    $this->artisan('jobs:expire');

    expect($today->fresh()->status)->toBe(JobListingStatus::Open);
});

it('does not resurrect or overwrite a listing the employer already closed', function () {
    $filled = JobListing::factory()->create([
        'employer_profile_id' => $this->employer->id,
        'status' => JobListingStatus::Filled,
        'application_deadline' => now()->subWeek()->toDateString(),
    ]);

    $this->artisan('jobs:expire');

    // A job that was filled did not expire, and conflating the two would make
    // the board's own statistics meaningless.
    expect($filled->fresh()->status)->toBe(JobListingStatus::Filled);
});

it('keeps expired listings off the public board', function () {
    $lapsed = JobListing::factory()->lapsed()->create(['employer_profile_id' => $this->employer->id]);
    $live = JobListing::factory()->open()->create(['employer_profile_id' => $this->employer->id]);

    $onBoard = JobListing::query()->onBoard()->pluck('id')->all();

    // Excluded by the deadline before the sweep has even run.
    expect($onBoard)->toContain($live->id)
        ->and($onBoard)->not->toContain($lapsed->id);
});
