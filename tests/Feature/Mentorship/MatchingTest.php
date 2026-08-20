<?php

use App\Enums\MentorStatus;
use App\Models\MentorProfile;
use App\Models\MentorshipMatch;
use App\Models\MentorshipPackage;
use App\Models\Specialisation;
use App\Models\User;
use App\Services\Ai\GeminiTagResolver;
use App\Services\Ai\NullTagResolver;
use App\Services\Ai\TagResolver;
use App\Services\Mentorship\MatchRequest;
use App\Services\Mentorship\MentorMatcher;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SpecialisationSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Matching a need to a mentor.
 *
 * The point of nearly every test here is the same: **the shortlist must not
 * depend on an external service being up.** With no key, with a timeout, with a
 * 500, with a hallucinated answer — a farmer still gets a ranked list.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(SpecialisationSeeder::class);

    $this->brooding = Specialisation::query()->where('slug', 'brooding')->firstOrFail();
    $this->feed = Specialisation::query()->where('slug', 'feed-formulation')->firstOrFail();

    $this->makeMentor = function (Specialisation $tag, int $priceKobo = 1_500_000, array $attributes = []): MentorProfile {
        $mentor = MentorProfile::factory()->approved()->create($attributes);
        $mentor->specialisations()->attach($tag);
        MentorshipPackage::factory()->pricedAt($priceKobo)->create(['mentor_profile_id' => $mentor->id]);

        return $mentor->fresh();
    };
});

it('uses the keyword fallback when nothing is configured', function () {
    // The default state of the application: no API key, so the container binds
    // the resolver that always declines.
    expect(app(TagResolver::class))->toBeInstanceOf(NullTagResolver::class);

    ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'My day old chicks keep dying in the brooder during the first week',
    ));

    expect($match->resolver)->toBe(MentorshipMatch::RESOLVER_KEYWORD)
        ->and($match->matched_specialisation_ids)->toContain($this->brooding->id)
        ->and($match->results_count)->toBe(1);
});

it('falls back when the provider answers with an error', function () {
    config()->set('services.gemini.key', 'test-key');
    Http::fake(['*' => Http::response(['error' => 'quota'], 429)]);

    $this->app->forgetInstance(TagResolver::class);
    $this->app->bind(TagResolver::class, fn () => new GeminiTagResolver);

    ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'My chicks are dying in the brooder in the first week',
    ));

    expect($match->resolver)->toBe(MentorshipMatch::RESOLVER_KEYWORD)
        ->and($match->resolver_note)->toContain('429')
        ->and($match->results_count)->toBe(1);
});

it('falls back when the provider answers with something unparseable', function () {
    config()->set('services.gemini.key', 'test-key');
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'sorry, I am a language model']]]]],
    ])]);

    $this->app->forgetInstance(TagResolver::class);
    $this->app->bind(TagResolver::class, fn () => new GeminiTagResolver);

    ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'brooding problems with day old chicks',
    ));

    expect($match->resolver)->toBe(MentorshipMatch::RESOLVER_KEYWORD)
        ->and($match->results_count)->toBe(1);
});

it('throws away tags the model invented', function () {
    config()->set('services.gemini.key', 'test-key');
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [[
            'text' => '{"slugs":["brooding","poultry-whispering","astrology"]}',
        ]]]]],
    ])]);

    $this->app->forgetInstance(TagResolver::class);
    $this->app->bind(TagResolver::class, fn () => new GeminiTagResolver);

    ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(need: 'something about chicks'));

    // The closed vocabulary, enforced: only the real tag survives.
    expect($match->resolver)->toBe(MentorshipMatch::RESOLVER_AI)
        ->and($match->matched_specialisation_ids)->toBe([$this->brooding->id]);
});

it('uses the AI answer when it names tags we recognise', function () {
    config()->set('services.gemini.key', 'test-key');
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"slugs":["feed-formulation"]}']]]]],
    ])]);

    $this->app->forgetInstance(TagResolver::class);
    $this->app->bind(TagResolver::class, fn () => new GeminiTagResolver);

    ($this->makeMentor)($this->feed);

    // Deliberately worded so no keyword would match — this only works if the
    // AI answer was actually used.
    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'I want to stop buying bags and do it myself to save money',
    ));

    expect($match->resolver)->toBe(MentorshipMatch::RESOLVER_AI)
        ->and($match->matched_specialisation_ids)->toBe([$this->feed->id]);
});

it('takes the client\'s own tags over anything inferred', function () {
    // Nothing is called at all when they ticked the boxes themselves.
    Http::fake(fn () => throw new RuntimeException('The AI layer must not be called.'));

    ($this->makeMentor)($this->feed);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'chicks dying in the brooder',
        specialisationIds: [$this->feed->id],
    ));

    expect($match->resolver)->toBe(MentorshipMatch::RESOLVER_EXPLICIT)
        ->and($match->matched_specialisation_ids)->toBe([$this->feed->id]);
});

it('ranks the better match first among mentors who both fit', function () {
    // Both do brooding, so both belong on the list. The one with a rating and
    // finished engagements behind them goes first.
    $seasoned = ($this->makeMentor)($this->brooding);
    $seasoned->forceFill(['average_rating' => 4.8, 'reviews_count' => 12, 'engagements_completed' => 20])->save();

    $newcomer = ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'brooding day old chicks, they keep dying',
    ));

    $ranked = collect($match->results);

    expect($ranked)->toHaveCount(2)
        ->and($ranked->first()['mentor_profile_id'])->toBe($seasoned->id)
        // But not by much: a newcomer who does exactly the right thing must
        // still be findable, or nobody ever gets a first engagement.
        ->and($ranked->last()['score'])
        ->toBeGreaterThan($ranked->first()['score'] * 0.8);
});

it('marks a mentor whose cheapest package is out of reach', function () {
    $cheap = ($this->makeMentor)($this->brooding, 500_000);
    $dear = ($this->makeMentor)($this->brooding, 9_000_000);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'brooding chicks',
        budgetMaxKobo: 1_000_000,
    ));

    $ranked = collect($match->results);

    expect($ranked->firstWhere('mentor_profile_id', $cheap->id)['affordable'])->toBeTrue()
        ->and($ranked->firstWhere('mentor_profile_id', $dear->id)['affordable'])->toBeFalse()
        // Still shown, but below. Somebody may stretch for the right person.
        ->and($ranked->first()['mentor_profile_id'])->toBe($cheap->id);
});

it('does not shortlist a mentor with nothing to sell', function () {
    $mentor = MentorProfile::factory()->approved()->create();
    $mentor->specialisations()->attach($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(need: 'brooding chicks'));

    // Showing them would be a dead end dressed up as a result.
    expect($match->results_count)->toBe(0);
});

it('does not shortlist a mentor who has not been approved', function () {
    $pending = MentorProfile::factory()->create();
    $pending->specialisations()->attach($this->brooding);
    MentorshipPackage::factory()->create(['mentor_profile_id' => $pending->id]);

    $match = app(MentorMatcher::class)->run(new MatchRequest(need: 'brooding chicks'));

    expect($match->results_count)->toBe(0);
});

it('drops a mentor suspended since the run when the shortlist is rendered', function () {
    $mentor = ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(need: 'brooding chicks'));

    expect($match->results_count)->toBe(1);

    $mentor->forceFill(['status' => MentorStatus::Suspended])->save();

    expect(app(MentorMatcher::class)->present($match->fresh()))->toBe([]);
});

it('stores the run so the ranking can be tuned later', function () {
    $user = User::factory()->create();
    ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'brooding day old chicks',
        state: 'Oyo',
        budgetMaxKobo: 5_000_000,
        user: $user,
    ));

    expect($match->user_id)->toBe($user->id)
        ->and($match->need_description)->toContain('brooding')
        ->and($match->preferred_state)->toBe('Oyo')
        ->and($match->budget_max_kobo)->toBe(5_000_000)
        // What we showed, and what we ranked it on.
        ->and($match->results)->toHaveCount(1)
        ->and($match->results[0])->toHaveKeys(['score', 'breakdown', 'matched_specialisation_ids']);
});

it('never puts contact details in a shortlist', function () {
    $mentor = ($this->makeMentor)($this->brooding, attributes: ['contact_value' => '08011112222']);

    $match = app(MentorMatcher::class)->run(new MatchRequest(need: 'brooding chicks'));

    $presented = app(MentorMatcher::class)->present($match);

    expect(json_encode($presented))->not->toContain('08011112222')
        ->and($presented[0])->not->toHaveKey('contact_value');

    $this->get(route('mentors.shortlist', $match))
        ->assertOk()
        ->assertDontSee('08011112222');
});

it('keeps somebody else\'s shortlist private', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(need: 'brooding chicks', user: $mine));

    $this->actingAs($theirs)->get(route('mentors.shortlist', $match))->assertForbidden();
    $this->actingAs($mine)->get(route('mentors.shortlist', $match))->assertOk();
});

it('answers with a shortlist even when nothing matched the words at all', function () {
    ($this->makeMentor)($this->brooding);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'zzzz qqqq nothing here resembles agriculture at all',
    ));

    // Everybody scores the same on relevance rather than everybody scoring
    // zero: a list ordered by reputation beats an empty page, and the shortlist
    // says how it was built.
    expect($match->matched_specialisation_ids)->toBe([])
        ->and($match->results_count)->toBe(1);
});

it('leaves out a mentor who does none of what was asked for', function () {
    $right = ($this->makeMentor)($this->brooding);
    ($this->makeMentor)($this->feed);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'brooding day old chicks, they keep dying',
    ));

    // A crop agronomist shown to somebody whose chicks are dying makes the
    // whole shortlist untrustworthy, however well the rest of it is ranked.
    expect($match->results_count)->toBe(1)
        ->and($match->results[0]['mentor_profile_id'])->toBe($right->id);
});

it('keeps everybody when it could not work out what was needed', function () {
    ($this->makeMentor)($this->brooding);
    ($this->makeMentor)($this->feed);

    $match = app(MentorMatcher::class)->run(new MatchRequest(
        need: 'zzzz qqqq nothing here resembles agriculture at all',
    ));

    // Nothing to be irrelevant to. A list ordered by reputation beats an empty
    // page, and the shortlist says plainly how it was built.
    expect($match->matched_specialisation_ids)->toBe([])
        ->and($match->results_count)->toBe(2);
});
