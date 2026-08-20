<?php

use App\Enums\RoleName;
use App\Models\EmployerProfile;
use App\Models\JobListing;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkerSkillSeeder;

/**
 * The rule as it holds over HTTP.
 *
 * The unit tests prove the model and the guard behave. These prove the actual
 * response body — because a number that is omitted from a method but present in
 * the page's props has still been sent to whoever asked for the page.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(WorkerSkillSeeder::class);

    $this->worker = WorkerProfile::factory()->create([
        'full_name' => 'Musa Bello',
        'phone' => '08031112222',
        'whatsapp' => '08033334444',
    ]);

    $this->employerUser = function (): User {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employer->value);
        EmployerProfile::factory()->create(['user_id' => $user->id]);

        return $user->fresh();
    };
});

it('keeps a worker\'s number out of the response for an employer\'s own listing board', function () {
    $employer = ($this->employerUser)();

    JobListing::factory()->open()->create([
        'employer_profile_id' => $employer->employerProfile->id,
    ]);

    $response = $this->actingAs($employer)->get(route('jobs.index'));

    // The public board is about jobs, not people. No worker data belongs here
    // at all, let alone a number.
    $response->assertOk();
    expect($response->getContent())->not->toContain('08031112222');
});

it('turns an anonymous visitor away from the worker directory', function () {
    $this->get(route('jobs.workers.index'))->assertRedirect(route('login'));
});

it('turns a signed-in non-employer away from the worker directory', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('jobs.workers.index'))
        ->assertForbidden();
});

it('sends no numbers in the directory payload even to an employer', function () {
    $employer = ($this->employerUser)();

    $response = $this->actingAs($employer)->get(route('jobs.workers.index'));

    $response->assertOk();

    // The directory is a list. Numbers are released one at a time, on a
    // worker's own page, where the release can be counted and recorded.
    expect($response->getContent())->not->toContain('08031112222')
        ->and($response->getContent())->toContain('Musa Bello');
});

it('sends the number on a worker page to an employer', function () {
    $employer = ($this->employerUser)();

    $response = $this->actingAs($employer)->get(route('jobs.workers.show', $this->worker->slug));

    $response->assertOk();
    expect($response->getContent())->toContain('08031112222');
});

it('sends no number on a worker page to a non-employer', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleName::Worker->value);
    WorkerProfile::factory()->create(['user_id' => $user->id]);

    // A worker looking at another worker gets the profile and no number.
    $response = $this->actingAs($user)->get(route('jobs.workers.show', $this->worker->slug));

    $response->assertForbidden();
});

it('degrades the worker page rather than the whole request once the limit is spent', function () {
    app(SettingsService::class)->set('worker_contact_daily_limit', 1, 'int', 'platform');

    $employer = ($this->employerUser)();

    $first = WorkerProfile::factory()->create(['phone' => '08050001111']);
    $second = WorkerProfile::factory()->create(['phone' => '08050002222']);

    $this->actingAs($employer)->get(route('jobs.workers.show', $first->slug))->assertOk();

    $response = $this->actingAs($employer)->get(route('jobs.workers.show', $second->slug));

    // Still a working page — refusing the number is not refusing the request.
    $response->assertOk();
    expect($response->getContent())->not->toContain('08050002222')
        ->and($response->getContent())->toContain('as many workers');
});

it('shows the board and one job to anybody', function () {
    $employer = ($this->employerUser)();

    $listing = JobListing::factory()->open()->create([
        'employer_profile_id' => $employer->employerProfile->id,
        'title' => 'Poultry attendant wanted',
    ]);

    $this->get(route('jobs.index'))->assertOk()->assertSee('Poultry attendant wanted', escape: false);
    $this->get(route('jobs.show', $listing->slug))->assertOk();
});

it('hides a draft listing from everybody but its owner', function () {
    $employer = ($this->employerUser)();

    $draft = JobListing::factory()->create([
        'employer_profile_id' => $employer->employerProfile->id,
    ]);

    $this->get(route('jobs.show', $draft->slug))->assertNotFound();
    $this->actingAs($employer)->get(route('jobs.show', $draft->slug))->assertOk();
});

it('carries the not-a-party notice on every page of the module', function () {
    $employer = ($this->employerUser)();

    $listing = JobListing::factory()->open()->create([
        'employer_profile_id' => $employer->employerProfile->id,
    ]);

    foreach ([
        route('jobs.index'),
        route('jobs.show', $listing->slug),
        route('jobs.workers.index'),
        route('jobs.workers.show', $this->worker->slug),
    ] as $url) {
        $response = $this->actingAs($employer)->get($url);

        $response->assertOk();

        // People will travel on the strength of these listings.
        expect($response->getContent())->toContain('we are not part of any agreement');
    }
});
