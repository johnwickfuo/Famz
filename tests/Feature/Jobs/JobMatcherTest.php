<?php

use App\Enums\JobType;
use App\Enums\PayPeriod;
use App\Enums\WorkTypeWanted;
use App\Models\EmployerProfile;
use App\Models\JobListing;
use App\Models\WorkerProfile;
use App\Models\WorkerSkill;
use App\Services\Jobs\JobMatcher;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkerSkillSeeder;

/**
 * Ranking, and being able to say why.
 *
 * Deterministic arithmetic rather than a model, which is what lets these
 * assertions exist at all: the same worker and the same job produce the same
 * score every time, and the reasons come out in words a person can check.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(WorkerSkillSeeder::class);

    $this->matcher = app(JobMatcher::class);
    $this->employer = EmployerProfile::factory()->create(['state' => 'Oyo']);

    $this->brooding = WorkerSkill::query()->where('name', 'Brooding')->firstOrFail();
    $this->vaccination = WorkerSkill::query()->where('name', 'Vaccination and medication')->firstOrFail();
    $this->security = WorkerSkill::query()->where('name', 'Farm security')->firstOrFail();

    $this->listing = function (array $skills = [], array $overrides = []): JobListing {
        $listing = JobListing::factory()->open()->create(array_merge([
            'employer_profile_id' => $this->employer->id,
            'state' => 'Oyo',
            'lga' => 'Akinyele',
            'job_type' => JobType::Permanent,
            'pay_min_kobo' => 5_000_000,
            'pay_max_kobo' => 7_000_000,
            'pay_period' => PayPeriod::Monthly,
        ], $overrides));

        $listing->skills()->sync(collect($skills)->pluck('id')->all());

        return $listing->load('skills');
    };

    $this->worker = function (array $skills = [], array $overrides = []): WorkerProfile {
        $worker = WorkerProfile::factory()->create(array_merge([
            'state' => 'Oyo',
            'lga' => 'Akinyele',
            'work_type_wanted' => WorkTypeWanted::Both,
            'expected_pay_min_kobo' => 5_000_000,
            'expected_pay_max_kobo' => 7_000_000,
            'pay_period' => PayPeriod::Monthly,
            'willing_to_relocate' => false,
        ], $overrides));

        $worker->skills()->sync(collect($skills)->pluck('id')->all());

        return $worker->load('skills');
    };
});

it('ranks the worker with the skills above the one without', function () {
    $listing = ($this->listing)([$this->brooding, $this->vaccination]);

    $fits = ($this->worker)([$this->brooding, $this->vaccination]);
    $doesNot = ($this->worker)([$this->security]);

    expect($this->matcher->score($listing, $fits)['score'])
        ->toBeGreaterThan($this->matcher->score($listing, $doesNot)['score']);
});

it('says why somebody ranked where they did', function () {
    $listing = ($this->listing)([$this->brooding]);
    $worker = ($this->worker)([$this->brooding]);

    $reasons = $this->matcher->score($listing, $worker)['reasons'];

    // A ranking that cannot explain itself is one nobody can argue with.
    expect($this->matcher->explain($reasons))->toContain('Brooding')
        ->toContain('Akinyele');
});

it('puts somebody in the same LGA above somebody in the same state', function () {
    $listing = ($this->listing)([$this->brooding]);

    $local = ($this->worker)([$this->brooding]);
    $sameState = ($this->worker)([$this->brooding], ['lga' => 'Ibadan North']);

    expect($this->matcher->score($listing, $local)['score'])
        ->toBeGreaterThan($this->matcher->score($listing, $sameState)['score']);
});

it('counts a willing mover, but below somebody already there', function () {
    $listing = ($this->listing)([$this->brooding]);

    $local = ($this->worker)([$this->brooding]);
    $mover = ($this->worker)([$this->brooding], ['state' => 'Kano', 'lga' => 'Dala', 'willing_to_relocate' => true]);
    $stuck = ($this->worker)([$this->brooding], ['state' => 'Kano', 'lga' => 'Dala', 'willing_to_relocate' => false]);

    $localScore = $this->matcher->score($listing, $local)['score'];
    $moverScore = $this->matcher->score($listing, $mover)['score'];
    $stuckScore = $this->matcher->score($listing, $stuck)['score'];

    // Relocating for farm work falls through often enough that a shortlist
    // ignoring it would waste an employer's week.
    expect($moverScore)->toBeLessThan($localScore)
        ->and($moverScore)->toBeGreaterThan($stuckScore);
});

it('scores a worker who wants the wrong kind of work below one who wants it', function () {
    $listing = ($this->listing)([$this->brooding], ['job_type' => JobType::Permanent]);

    $wants = ($this->worker)([$this->brooding], ['work_type_wanted' => WorkTypeWanted::Permanent]);
    $doesNot = ($this->worker)([$this->brooding], ['work_type_wanted' => WorkTypeWanted::Temporary]);

    expect($this->matcher->score($listing, $wants)['score'])
        ->toBeGreaterThan($this->matcher->score($listing, $doesNot)['score']);
});

it('treats a contract as temporary from the worker\'s side', function () {
    // A fixed-term job is not a permanent one however it is written up.
    expect(WorkTypeWanted::Temporary->accepts(JobType::Contract))->toBeTrue()
        ->and(WorkTypeWanted::Permanent->accepts(JobType::Contract))->toBeFalse();
});

it('compares a daily rate with a monthly expectation fairly', function () {
    // 3,000 a day is about 78,000 a month, which clears a 60,000 expectation.
    $daily = ($this->listing)([$this->brooding], [
        'pay_min_kobo' => 300_000,
        'pay_max_kobo' => 300_000,
        'pay_period' => PayPeriod::Daily,
    ]);

    $worker = ($this->worker)([$this->brooding], [
        'expected_pay_min_kobo' => 6_000_000,
        'expected_pay_max_kobo' => 8_000_000,
    ]);

    expect($this->matcher->explain($this->matcher->score($daily, $worker)['reasons']))
        ->toContain('Pay is in range');
});

it('does not punish a listing or a worker for leaving pay open', function () {
    $listing = ($this->listing)([$this->brooding], ['pay_min_kobo' => null, 'pay_max_kobo' => null]);

    $stated = ($this->worker)([$this->brooding]);
    $open = ($this->worker)([$this->brooding], [
        'expected_pay_min_kobo' => null,
        'expected_pay_max_kobo' => null,
    ]);

    // "Negotiable" is common and honest. Punishing it would push the most
    // flexible people to the bottom of every list.
    expect($this->matcher->score($listing, $stated)['score'])
        ->toBe($this->matcher->score($listing, $open)['score']);
});

it('scores a slightly low offer above a derisory one', function () {
    $worker = ($this->worker)([$this->brooding], [
        'expected_pay_min_kobo' => 6_000_000,
        'expected_pay_max_kobo' => 6_000_000,
    ]);

    $close = ($this->listing)([$this->brooding], ['pay_min_kobo' => 5_500_000, 'pay_max_kobo' => 5_500_000]);
    $derisory = ($this->listing)([$this->brooding], ['pay_min_kobo' => 2_000_000, 'pay_max_kobo' => 2_000_000]);

    // Somebody asking 60,000 will often take 55,000 and almost never take
    // 20,000.
    expect($this->matcher->score($close, $worker)['score'])
        ->toBeGreaterThan($this->matcher->score($derisory, $worker)['score']);
});

it('treats a listing naming no skills as a partial match for everybody', function () {
    $general = ($this->listing)([]);

    $skilled = ($this->worker)([$this->brooding, $this->vaccination]);
    $none = ($this->worker)([]);

    // A listing with no named skills is asking for general farm labour.
    expect($this->matcher->score($general, $skilled)['score'])
        ->toBe($this->matcher->score($general, $none)['score']);
});

it('ranks both directions the same way round', function () {
    $listing = ($this->listing)([$this->brooding]);
    $fits = ($this->worker)([$this->brooding]);
    $doesNot = ($this->worker)([$this->security]);

    $forEmployer = $this->matcher->rankWorkersFor($listing, collect([$doesNot, $fits]));

    $forWorker = $this->matcher->rankListingsFor(
        $fits,
        collect([$listing, ($this->listing)([$this->security], ['state' => 'Kano', 'lga' => 'Dala'])]),
    );

    // A worker and a job that suit each other should agree about it whichever
    // side is looking.
    expect($forEmployer->first()['worker']->id)->toBe($fits->id)
        ->and($forWorker->first()['listing']->id)->toBe($listing->id);
});

it('matches place names typed with different capitals and spacing', function () {
    $listing = ($this->listing)([$this->brooding], ['state' => 'Oyo ', 'lga' => 'Akinyele']);
    $worker = ($this->worker)([$this->brooding], ['state' => 'oyo', 'lga' => 'AKINYELE']);

    // Two different people typed these. If they do not match, the location
    // score never fires and the whole ranking is quietly wrong.
    expect($this->matcher->explain($this->matcher->score($listing, $worker)['reasons']))
        ->toContain('Akinyele');
});
