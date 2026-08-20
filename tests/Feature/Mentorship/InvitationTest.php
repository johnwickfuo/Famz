<?php

use App\Enums\RoleName;
use App\Models\MentorInvitation;
use App\Models\MentorProfile;
use App\Models\Specialisation;
use App\Models\User;
use App\Services\Mentorship\InvitationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SpecialisationSeeder;
use Illuminate\Support\Facades\URL;

/**
 * Mentors join by invitation and by no other route.
 *
 * The signature stops a link being constructed; the token stops a link being
 * reused. Both are tested, because either alone would be a way in.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(SpecialisationSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);

    $this->invitations = app(InvitationService::class);

    $this->details = fn (array $overrides = []): array => array_merge([
        'name' => 'Bola Adewale',
        'email' => 'bola@example.test',
        'phone' => '08030000000',
        'state' => 'Oyo',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'headline' => 'Poultry farm manager, 14 years',
        'bio' => 'Fourteen years running commercial layer farms across the south west.',
        'strengths' => 'Getting a flock back into lay after a disease knock.',
        'specialisations' => Specialisation::query()->limit(2)->pluck('id')->all(),
        'years_experience' => 14,
        'preferred_contact_method' => 'whatsapp',
        'contact_value' => '08030000000',
        'accepts_remote' => true,
        'accepts_in_person' => false,
    ], $overrides);
});

it('creates a mentor profile in exactly one place in the whole application', function () {
    /*
     * The product decision, asserted against the source rather than against a
     * hand-picked list of routes: mentors exist because somebody was invited,
     * so there must be exactly one piece of code that can bring one into
     * being, and it must be the one that spends an invitation.
     */
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        $creates = str_contains($source, 'new MentorProfile')
            || preg_match('/MentorProfile::(query\(\)->)?(create|firstOrCreate|updateOrCreate|make)\(/', $source) === 1;

        if ($creates && $relative !== 'app/Services/Mentorship/InvitationService.php') {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('has no route named for creating a mentor outside the invitation flow', function () {
    $names = collect(app('router')->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->filter(fn (string $name): bool => str_starts_with($name, 'mentors.'))
        ->values();

    // Everything under mentors.* is either the invitation flow or read-only
    // discovery. There is no mentors.create and no mentors.store.
    expect($names->all())->toEqualCanonicalizing([
        'mentors.find',
        'mentors.match',
        'mentors.shortlist',
        'mentors.join',
        'mentors.join.store',
        'mentors.pending',
        'mentors.show',
    ]);
});

it('opens the registration form for a signed link with a good token', function () {
    $invitation = $this->invitations->create($this->admin);

    $this->get($this->invitations->urlFor($invitation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Mentors/Join'));
});

it('refuses an unsigned link even when the token is perfectly good', function () {
    $invitation = $this->invitations->create($this->admin);

    // Somebody who guessed or was told the token still cannot get in without
    // the signature we made.
    $this->get(route('mentors.join', $invitation->token))->assertForbidden();
});

it('refuses a link whose signature was tampered with', function () {
    $invitation = $this->invitations->create($this->admin);

    $url = $this->invitations->urlFor($invitation);

    $this->get($url.'0')->assertForbidden();
});

it('keeps the page out of search results', function () {
    $invitation = $this->invitations->create($this->admin);

    $this->get($this->invitations->urlFor($invitation))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

it('disallows the join path in robots.txt', function () {
    // Served from a route rather than a static file, so that the Sitemap
    // directive can carry an absolute URL.
    expect($this->get('/robots.txt')->getContent())
        ->toContain('Disallow: /mentors/join');
});

it('creates the mentor, the role and the tags when the invitation is good', function () {
    $invitation = $this->invitations->create($this->admin);

    $this->post(route('mentors.join.store', $invitation->token), ($this->details)())
        ->assertRedirect(route('mentors.pending'));

    $user = User::query()->where('email', 'bola@example.test')->firstOrFail();

    expect($user->hasRole(RoleName::Mentor->value))->toBeTrue();

    $profile = $user->mentorProfile;

    expect($profile)->not->toBeNull()
        // Registered, not approved. Two separate decisions.
        ->and($profile->status->value)->toBe('pending')
        ->and($profile->isBookable())->toBeFalse()
        ->and($profile->specialisations()->count())->toBe(2)
        // The paragraph AND the tags, doing different jobs.
        ->and($profile->strengths)->toContain('back into lay');
});

it('spends the invitation, so the same link cannot be used twice', function () {
    $invitation = $this->invitations->create($this->admin);

    $this->post(route('mentors.join.store', $invitation->token), ($this->details)())
        ->assertRedirect(route('mentors.pending'));

    expect($invitation->fresh()->isUsed())->toBeTrue();

    $this->post(route('mentors.join.store', $invitation->token), ($this->details)([
        'email' => 'somebody-else@example.test',
    ]))->assertSessionHas('error');

    expect(MentorProfile::query()->count())->toBe(1);
});

it('refuses an expired invitation', function () {
    $invitation = MentorInvitation::factory()->expired()->create(['created_by' => $this->admin->id]);

    $this->post(route('mentors.join.store', $invitation->token), ($this->details)())
        ->assertSessionHas('error');

    expect(MentorProfile::query()->count())->toBe(0);
});

it('shows a spent invitation a sentence rather than a dead end', function () {
    $invitation = MentorInvitation::factory()->used()->create(['created_by' => $this->admin->id]);

    // Signed by hand: urlFor() would sign until expires_at, which is fine, but
    // the point is that the page past the signature explains itself.
    $url = URL::temporarySignedRoute('mentors.join', now()->addDay(), ['token' => $invitation->token]);

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mentors/JoinClosed')
            ->where('reason', 'This invitation has already been used.'));
});

it('will not let an addressed invitation be redeemed by somebody else', function () {
    $invitation = $this->invitations->create($this->admin, email: 'invited@example.test');

    $this->post(route('mentors.join.store', $invitation->token), ($this->details)([
        'email' => 'gatecrasher@example.test',
    ]))->assertSessionHas('error');

    expect(MentorProfile::query()->count())->toBe(0)
        ->and($invitation->fresh()->isUsed())->toBeFalse();
});

it('refuses a registration with no specialisation ticked', function () {
    $invitation = $this->invitations->create($this->admin);

    // A mentor with no tags can never be shortlisted, so this is refused
    // rather than allowed to become a silent dead end.
    $this->post(route('mentors.join.store', $invitation->token), ($this->details)([
        'specialisations' => [],
    ]))->assertSessionHasErrors('specialisations');

    expect(MentorProfile::query()->count())->toBe(0);
});

it('404s a token that never existed', function () {
    $url = URL::temporarySignedRoute('mentors.join', now()->addDay(), ['token' => 'not-a-real-token']);

    $this->get($url)->assertNotFound();
});
