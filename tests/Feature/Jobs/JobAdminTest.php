<?php

use App\Enums\JobApplicationStatus;
use App\Enums\JobListingStatus;
use App\Enums\RatingStatus;
use App\Enums\RoleName;
use App\Filament\Admin\Pages\ContactDisclosures;
use App\Filament\Admin\Resources\JobListings\Pages\ListJobListings;
use App\Filament\Admin\Resources\JobRatings\JobRatingResource;
use App\Filament\Admin\Resources\JobRatings\Pages\ListJobRatings;
use App\Filament\Admin\Resources\WorkerProfiles\Pages\ListWorkerProfiles;
use App\Models\EmployerProfile;
use App\Models\JobListing;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\Jobs\ApplicationService;
use App\Services\Jobs\RatingService;
use App\Services\Jobs\WorkerContactGuard;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->admin->assignRole(RoleName::Admin->value);
    $this->actingAs($this->admin);

    $this->employerUser = User::factory()->create();
    $this->employerUser->assignRole(RoleName::Employer->value);
    $this->employer = EmployerProfile::factory()->create(['user_id' => $this->employerUser->id]);

    $this->workerUser = User::factory()->create();
    $this->workerUser->assignRole(RoleName::Worker->value);
    $this->worker = WorkerProfile::factory()->create(['user_id' => $this->workerUser->id]);

    $this->listing = JobListing::factory()->open()->create(['employer_profile_id' => $this->employer->id]);

    $this->pendingRating = function () {
        $application = app(ApplicationService::class)->apply($this->listing, $this->worker);
        app(ApplicationService::class)->moveTo($application, JobApplicationStatus::Hired, $this->employerUser);

        return app(RatingService::class)->leave($application->fresh(), $this->employerUser, 5, 'Reliable.');
    };
});

// ---------------------------------------------------------------------------
// Moderation
// ---------------------------------------------------------------------------

it('counts unread ratings in the navigation badge', function () {
    expect(JobRatingResource::getNavigationBadge())->toBeNull();

    ($this->pendingRating)();

    // Nothing is visible to either side until somebody reads it, so an
    // unattended queue is the thing this badge exists to prevent.
    expect(JobRatingResource::getNavigationBadge())->toBe('1');
});

it('opens on the unread ratings', function () {
    expect(livewire(ListJobRatings::class)->instance()->getDefaultActiveTab())->toBe('waiting');
});

it('publishes a rating and moves the worker\'s average', function () {
    $rating = ($this->pendingRating)();

    livewire(ListJobRatings::class)->callAction(TestAction::make('approve')->table($rating));

    expect($rating->fresh()->status)->toBe(RatingStatus::Approved)
        ->and($this->worker->fresh()->rating_count)->toBe(1);
});

it('refuses a rating with a reason and keeps it out of the average', function () {
    $rating = ($this->pendingRating)();

    livewire(ListJobRatings::class)->callAction(
        TestAction::make('reject')->table($rating),
        ['note' => 'Personal abuse.'],
    );

    $rating->refresh();

    expect($rating->status)->toBe(RatingStatus::Rejected)
        ->and($rating->moderation_note)->toContain('abuse')
        ->and($rating->moderated_by)->toBe($this->admin->id)
        ->and($this->worker->fresh()->rating_count)->toBe(0);
});

// ---------------------------------------------------------------------------
// Listings
// ---------------------------------------------------------------------------

it('opens on the live listings', function () {
    expect(livewire(ListJobListings::class)->instance()->getDefaultActiveTab())->toBe('live');
});

it('takes a listing off the board with a reason', function () {
    livewire(ListJobListings::class)->callAction(
        TestAction::make('takeDown')->table($this->listing),
        ['reason' => 'Asking applicants for a fee.'],
    );

    $this->listing->refresh();

    expect($this->listing->status)->toBe(JobListingStatus::Closed)
        ->and($this->listing->acceptsApplications())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Workers
// ---------------------------------------------------------------------------

it('never puts a phone number in the workers table', function () {
    $this->worker->forceFill(['phone' => '08099998888'])->save();

    $response = livewire(ListWorkerProfiles::class)->assertCanSeeTableRecords([$this->worker]);

    /*
     * A sortable list of every worker's number is exactly the artefact the
     * contact rule exists to prevent, and it would not matter that only staff
     * can open it.
     */
    expect($response->html())->not->toContain('08099998888');
});

it('takes a worker profile down without losing their history', function () {
    livewire(ListWorkerProfiles::class)->callAction(
        TestAction::make('suspend')->table($this->worker),
        ['reason' => 'Fake profile.'],
    );

    expect($this->worker->fresh()->is_active)->toBeFalse()
        // Still in the database, still connected to whatever they applied for.
        ->and(WorkerProfile::query()->whereKey($this->worker->id)->exists())->toBeTrue();
});

it('hides a suspended worker from the directory but not from the admin', function () {
    $this->worker->forceFill(['is_active' => false])->save();

    expect(WorkerProfile::query()->openToWork()->pluck('id')->all())->not->toContain($this->worker->id);

    livewire(ListWorkerProfiles::class)
        ->set('activeTab', 'suspended')
        ->assertCanSeeTableRecords([$this->worker]);
});

// ---------------------------------------------------------------------------
// Harvesting detection
// ---------------------------------------------------------------------------

it('reports who has been given numbers', function () {
    $guard = app(WorkerContactGuard::class);

    $others = WorkerProfile::factory()->count(3)->create();

    foreach ($others as $worker) {
        $guard->release($worker, $this->employerUser);
    }

    $data = livewire(ContactDisclosures::class)->instance()->getViewData();

    expect($data['totals']['released'])->toBe(3)
        ->and($data['totals']['workers'])->toBe(3)
        ->and($data['totals']['accounts'])->toBe(1)
        ->and($data['topViewers'][0]['workers_seen'])->toBe(3);
});

it('counts a refused view without counting it as a release', function () {
    $guard = app(WorkerContactGuard::class);

    $guard->release($this->worker, null);

    $data = livewire(ContactDisclosures::class)->instance()->getViewData();

    // Refusals are the rule working, not a failure — but a run of them is
    // still a pattern worth being able to see.
    expect($data['totals']['released'])->toBe(0)
        ->and($data['totals']['refused'])->toBe(1);
});

it('flags an account running at its daily allowance', function () {
    app(SettingsService::class)->set('worker_contact_daily_limit', 5, 'int', 'platform');

    $guard = app(WorkerContactGuard::class);

    foreach (WorkerProfile::factory()->count(5)->create() as $worker) {
        $guard->release($worker, $this->employerUser);
    }

    $data = livewire(ContactDisclosures::class)->instance()->getViewData();

    // Somebody averaging their full allowance every day they are active is not
    // filling one job, whatever they say they are doing.
    expect($data['topViewers'][0]['at_limit'])->toBeTrue()
        ->and($data['topViewers'][0]['per_day'])->toBe(5.0);
});

it('ranks by distinct workers rather than by raw view count', function () {
    $guard = app(WorkerContactGuard::class);

    $busy = $this->employerUser;

    $quiet = User::factory()->create();
    $quiet->assignRole(RoleName::Employer->value);
    EmployerProfile::factory()->create(['user_id' => $quiet->id]);
    $quiet = $quiet->fresh();

    // One person opening ten different people.
    foreach (WorkerProfile::factory()->count(4)->create() as $worker) {
        $guard->release($worker, $busy);
    }

    // Another refreshing one profile repeatedly, which is uninteresting.
    $single = WorkerProfile::factory()->create();
    foreach (range(1, 8) as $ignored) {
        $guard->release($single, $quiet);
    }

    $data = livewire(ContactDisclosures::class)->instance()->getViewData();

    expect($data['topViewers'][0]['workers_seen'])->toBe(4);
});

it('renders the disclosures page', function () {
    livewire(ContactDisclosures::class)->assertOk();
});
