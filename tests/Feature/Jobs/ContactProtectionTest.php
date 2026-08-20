<?php

use App\Enums\RoleName;
use App\Models\EmployerProfile;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Models\WorkerProfileView;
use App\Services\Jobs\WorkerContactGuard;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * The phone number rule.
 *
 * A farm worker's number is often the only asset they have in a job search.
 * Handed out freely it becomes a call list, and these are people who will
 * travel to an unfamiliar village on the strength of a phone call. Every test
 * here exists because the rule has to hold at the payload, not at the template
 * — a number hidden with CSS has still been sent.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->guard = app(WorkerContactGuard::class);
    $this->settings = app(SettingsService::class);

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

// ---------------------------------------------------------------------------
// Who gets the number
// ---------------------------------------------------------------------------

it('gives an employer the number', function () {
    $employer = ($this->employerUser)();

    expect($this->worker->contactVisibleTo($employer))->toBeTrue()
        ->and($this->worker->contactFor($employer))->toMatchArray([
            'phone' => '08031112222',
            'whatsapp' => '08033334444',
        ]);
});

it('gives an anonymous visitor nothing', function () {
    expect($this->worker->contactVisibleTo(null))->toBeFalse()
        ->and($this->worker->contactFor(null))->toBeNull();
});

it('gives a signed-in non-employer nothing', function () {
    $user = User::factory()->create();

    // Logged in is not the test. The employer role is.
    expect($this->worker->contactVisibleTo($user))->toBeFalse()
        ->and($this->worker->contactFor($user))->toBeNull();
});

it('gives a worker their own number back', function () {
    $owner = $this->worker->user;

    expect($this->worker->contactFor($owner))->not->toBeNull();
});

it('leaves no contact key at all in the public card', function () {
    $card = $this->worker->publicCard();

    // Not an empty key, not a null one. A key that is sometimes populated is a
    // key somebody eventually populates by mistake.
    expect($card)->not->toHaveKey('phone')
        ->and($card)->not->toHaveKey('whatsapp')
        ->and($card)->not->toHaveKey('contact');
});

it('keeps the number out of the model\'s own array form', function () {
    // The backstop for somebody one day returning the model straight out of a
    // controller.
    $array = $this->worker->toArray();

    expect($array)->not->toHaveKey('phone')
        ->and($array)->not->toHaveKey('whatsapp')
        ->and($array)->not->toHaveKey('id_document')
        ->and(json_encode($array))->not->toContain('08031112222');
});

// ---------------------------------------------------------------------------
// The guard: releasing, refusing and recording
// ---------------------------------------------------------------------------

it('releases to an employer and writes the disclosure down', function () {
    $employer = ($this->employerUser)();

    $result = $this->guard->release($this->worker, $employer);

    expect($result['contact'])->not->toBeNull()
        ->and($result['reason'])->toBe('released')
        ->and(WorkerProfileView::query()->releasing()->count())->toBe(1);
});

it('logs a refused view as well as a released one', function () {
    $this->guard->release($this->worker, null);

    // A run of look-but-no-contact requests is itself a pattern worth seeing.
    expect(WorkerProfileView::query()->count())->toBe(1)
        ->and(WorkerProfileView::query()->releasing()->count())->toBe(0);
});

it('tells an anonymous visitor why, and a non-employer something different', function () {
    expect($this->guard->release($this->worker, null)['reason'])->toBe('anonymous')
        ->and($this->guard->release($this->worker, User::factory()->create())['reason'])
        ->toBe('not_an_employer');
});

it('does not log a worker looking at their own profile', function () {
    $result = $this->guard->release($this->worker, $this->worker->user);

    // Not a disclosure to anybody, so it must not burn an allowance or leave a
    // harvesting-shaped trail.
    expect($result['reason'])->toBe('self')
        ->and($result['contact'])->not->toBeNull()
        ->and(WorkerProfileView::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

it('stops an employer after the daily allowance is spent', function () {
    $this->settings->set('worker_contact_daily_limit', 3, 'int', 'platform');

    $employer = ($this->employerUser)();

    $workers = WorkerProfile::factory()->count(4)->create();

    foreach ($workers->take(3) as $worker) {
        expect($this->guard->release($worker, $employer)['reason'])->toBe('released');
    }

    $fourth = $this->guard->release($workers->last(), $employer);

    expect($fourth['contact'])->toBeNull()
        ->and($fourth['reason'])->toBe('daily_limit')
        ->and($this->guard->remainingToday($employer))->toBe(0);
});

it('does not charge an employer twice for the same worker', function () {
    $this->settings->set('worker_contact_daily_limit', 2, 'int', 'platform');

    $employer = ($this->employerUser)();

    $this->guard->release($this->worker, $employer);
    $this->guard->release($this->worker, $employer);
    $this->guard->release($this->worker, $employer);

    // An employer comparing three candidates will revisit each of them, and
    // charging for it would burn the allowance on ordinary use.
    expect($this->guard->usedToday($employer))->toBe(1)
        ->and($this->guard->remainingToday($employer))->toBe(1);
});

it('lets a revisit through even once the allowance is spent', function () {
    $this->settings->set('worker_contact_daily_limit', 1, 'int', 'platform');

    $employer = ($this->employerUser)();

    $this->guard->release($this->worker, $employer);

    $another = WorkerProfile::factory()->create();
    expect($this->guard->release($another, $employer)['reason'])->toBe('daily_limit');

    // They already have this one's number. Refusing it now would be theatre.
    expect($this->guard->release($this->worker, $employer)['reason'])->toBe('released');
});

it('resets the allowance the next day', function () {
    $this->settings->set('worker_contact_daily_limit', 1, 'int', 'platform');

    $employer = ($this->employerUser)();

    $this->guard->release(WorkerProfile::factory()->create(), $employer);
    expect($this->guard->remainingToday($employer))->toBe(0);

    $this->travel(1)->days();

    expect($this->guard->remainingToday($employer))->toBe(1);
});

it('counts each employer account separately', function () {
    $this->settings->set('worker_contact_daily_limit', 1, 'int', 'platform');

    $first = ($this->employerUser)();
    $second = ($this->employerUser)();

    $this->guard->release($this->worker, $first);

    expect($this->guard->remainingToday($first))->toBe(0)
        ->and($this->guard->remainingToday($second))->toBe(1);
});
