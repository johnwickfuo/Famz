<?php

use App\Enums\EngagementStatus;
use App\Enums\ReviewStatus;
use App\Enums\RoleName;
use App\Models\MentorProfile;
use App\Models\MentorReview;
use App\Models\MentorshipEngagement;
use App\Models\MentorshipPackage;
use App\Models\User;
use App\Services\Mentorship\ReviewService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SpecialisationSeeder;

/**
 * Reviews.
 *
 * Two gates, both load-bearing: only a client whose engagement FINISHED may
 * write one, and nothing is visible or counts toward a rating until an
 * administrator has read it.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(SpecialisationSeeder::class);

    $this->reviews = app(ReviewService::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);

    $this->client = User::factory()->create(['name' => 'Ngozi Okafor']);
    $this->mentor = MentorProfile::factory()->approved()->create();
    $this->package = MentorshipPackage::factory()->create(['mentor_profile_id' => $this->mentor->id]);

    $this->engagementIn = function (EngagementStatus $status): MentorshipEngagement {
        $engagement = new MentorshipEngagement;

        $engagement->forceFill([
            'client_id' => $this->client->id,
            'mentor_profile_id' => $this->mentor->id,
            'mentorship_package_id' => $this->package->id,
            'package_title' => $this->package->title,
            'billing_type' => $this->package->billing_type,
            'price_kobo' => $this->package->price_kobo,
            'commission_percent_snapshot' => 15,
            'platform_amount_kobo' => 150_000,
            'mentor_amount_kobo' => 850_000,
            'status' => $status,
            'completed_at' => $status === EngagementStatus::Completed ? now() : null,
        ])->save();

        return $engagement->refresh();
    };
});

it('refuses a review on an engagement that is still running', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Active);

    expect($this->reviews->canReview($engagement, $this->client))->toBeFalse();

    $this->actingAs($this->client)
        ->post(route('mentorship.review', $engagement), ['rating' => 5])
        ->assertSessionHas('error');

    expect(MentorReview::query()->count())->toBe(0);
});

it('refuses a review from somebody who was not the client', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Completed);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post(route('mentorship.review', $engagement), ['rating' => 1])
        ->assertForbidden();

    expect(MentorReview::query()->count())->toBe(0);
});

it('accepts one review from the client of a finished engagement', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Completed);

    $this->actingAs($this->client)
        ->post(route('mentorship.review', $engagement), [
            'rating' => 4,
            'comment' => 'Came to the farm and fixed the ventilation in an afternoon.',
        ])
        ->assertRedirect();

    $review = MentorReview::query()->firstOrFail();

    expect($review->rating)->toBe(4)
        ->and($review->status)->toBe(ReviewStatus::Pending);
});

it('allows only one review per engagement', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Completed);

    $this->reviews->leave($engagement, $this->client, 5, 'First');

    expect(fn () => $this->reviews->leave($engagement->fresh(), $this->client, 1, 'Second'))
        ->toThrow(RuntimeException::class);

    expect(MentorReview::query()->count())->toBe(1);
});

it('keeps a review off the public profile until it is approved', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Completed);

    $this->reviews->leave($engagement, $this->client, 5, 'Absolutely brilliant, hire him');

    $this->get(route('mentors.show', $this->mentor))
        ->assertOk()
        ->assertDontSee('Absolutely brilliant')
        ->assertInertia(fn ($page) => $page->where('reviews', []));

    // And it has not moved the rating either.
    expect($this->mentor->fresh()->average_rating)->toBeNull()
        ->and($this->mentor->fresh()->reviews_count)->toBe(0);
});

it('publishes it and recalculates the rating on approval', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Completed);

    $review = $this->reviews->leave($engagement, $this->client, 4, 'Very good, knew the disease straight away');

    $this->reviews->approve($review, $this->admin);

    $this->get(route('mentors.show', $this->mentor))
        ->assertOk()
        ->assertSee('knew the disease straight away');

    $mentor = $this->mentor->fresh();

    expect($mentor->average_rating)->toBe(4.0)
        ->and($mentor->reviews_count)->toBe(1);
});

it('averages only the approved reviews', function () {
    $first = ($this->engagementIn)(EngagementStatus::Completed);
    $second = ($this->engagementIn)(EngagementStatus::Completed);

    $this->reviews->approve($this->reviews->leave($first, $this->client, 5, null), $this->admin);
    $this->reviews->leave($second, $this->client, 1, 'Never turned up');

    // The one-star is still waiting on moderation, so it counts for nothing.
    expect($this->mentor->fresh()->average_rating)->toBe(5.0)
        ->and($this->mentor->fresh()->reviews_count)->toBe(1);

    $this->reviews->approve(MentorReview::query()->where('rating', 1)->firstOrFail(), $this->admin);

    expect($this->mentor->fresh()->average_rating)->toBe(3.0)
        ->and($this->mentor->fresh()->reviews_count)->toBe(2);
});

it('takes an approved review back out of the rating if it is later rejected', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Completed);

    $review = $this->reviews->leave($engagement, $this->client, 1, 'Written in a temper');
    $this->reviews->approve($review, $this->admin);

    expect($this->mentor->fresh()->average_rating)->toBe(1.0);

    $this->reviews->reject($review->fresh(), $this->admin, 'Abusive language, and the work was in fact done.');

    expect($this->mentor->fresh()->average_rating)->toBeNull()
        ->and($this->mentor->fresh()->reviews_count)->toBe(0);
});

it('will not reject a review without saying why', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Completed);
    $review = $this->reviews->leave($engagement, $this->client, 2, null);

    // A rejection nobody explains is indistinguishable from censorship, and
    // both the mentor and the client read the note.
    expect(fn () => $this->reviews->reject($review, $this->admin, '   '))
        ->toThrow(RuntimeException::class);
});

it('shows only a first name and an initial on a published review', function () {
    $engagement = ($this->engagementIn)(EngagementStatus::Completed);

    $review = $this->reviews->leave($engagement, $this->client, 5, 'Good');
    $this->reviews->approve($review, $this->admin);

    // Enough to read as a person's, without publishing a client list.
    expect($review->fresh()->card()['by'])->toBe('Ngozi O.');

    $this->get(route('mentors.show', $this->mentor))
        ->assertOk()
        ->assertDontSee($this->client->email);
});
