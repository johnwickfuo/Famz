<?php

use App\Enums\EngagementStatus;
use App\Enums\MentorStatus;
use App\Enums\RoleName;
use App\Filament\Admin\Resources\MentorInvitations\Pages\ListMentorInvitations;
use App\Filament\Admin\Resources\MentorReviews\Pages\ListMentorReviews;
use App\Filament\Admin\Resources\Mentors\Pages\ListMentors;
use App\Filament\Admin\Resources\Specialisations\Pages\ListSpecialisations;
use App\Filament\Mentor\Resources\Engagements\Pages\ListEngagements;
use App\Filament\Mentor\Resources\Packages\Pages\CreatePackage;
use App\Filament\Mentor\Resources\Packages\Pages\ListPackages;
use App\Filament\Mentor\Resources\Reviews\Pages\ListReviews;
use App\Models\MentorInvitation;
use App\Models\MentorProfile;
use App\Models\MentorshipPackage;
use App\Models\Specialisation;
use App\Models\User;
use App\Services\Mentorship\EngagementService;
use App\Services\Mentorship\ReviewService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SpecialisationSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

/**
 * The two panels.
 *
 * Most of this is ownership: a mentor's screens must not be able to show
 * somebody else's work, and the admin screens must refuse the two things that
 * would produce a dead end for a client.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(SpecialisationSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);

    $this->mentor = MentorProfile::factory()->approved()->create();
    $this->mentor->user->assignRole(RoleName::Mentor->value);
    $this->mentor->specialisations()->attach(Specialisation::query()->first());
});

it('shows an administrator the mentorship screens', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    MentorInvitation::factory()->create(['created_by' => $this->admin->id]);

    livewire(ListMentorInvitations::class)->assertOk();
    livewire(ListMentors::class)->assertCanSeeTableRecords([$this->mentor])->assertOk();
    livewire(ListMentorReviews::class)->assertOk();
    livewire(ListSpecialisations::class)->assertOk();
});

it('refuses to approve a mentor with nothing to sell', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $pending = MentorProfile::factory()->create();

    livewire(ListMentors::class)->callAction(TestAction::make('approve')->table($pending));

    // Approving them would put a dead end in front of every client who found
    // them: nothing to buy, so nothing to hire.
    expect($pending->fresh()->status)->toBe(MentorStatus::Pending);
});

it('approves a mentor who has a package', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $pending = MentorProfile::factory()->create();
    MentorshipPackage::factory()->create(['mentor_profile_id' => $pending->id]);

    livewire(ListMentors::class)->callAction(TestAction::make('approve')->table($pending));

    expect($pending->fresh()->status)->toBe(MentorStatus::Approved)
        ->and($pending->fresh()->approved_by)->toBe($this->admin->id);
});

it('never shows a mentor\'s private contact value on the admin screens', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $this->mentor->forceFill(['contact_value' => '08055556666'])->save();

    // An administrator has no reason to read somebody's private number out of a
    // moderation screen, so it is absent there too.
    livewire(ListMentors::class)->assertDontSee('08055556666');
});

it('lets a mentor manage their own packages and nobody else\'s', function () {
    Filament::setCurrentPanel('mentor');
    $this->actingAs($this->mentor->user);

    $mine = MentorshipPackage::factory()->create(['mentor_profile_id' => $this->mentor->id]);
    $theirs = MentorshipPackage::factory()->create();

    livewire(ListPackages::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('files a new package under the signed-in mentor, whatever the form says', function () {
    Filament::setCurrentPanel('mentor');
    $this->actingAs($this->mentor->user);

    $somebodyElse = MentorProfile::factory()->approved()->create();

    livewire(CreatePackage::class)
        ->fillForm([
            'title' => 'One-hour call',
            'description' => 'An hour on the phone going through your figures.',
            'billing_type' => 'one_time',
            'price_naira' => 15000,
            'is_active' => true,
            // Deliberately trying to file it under somebody else.
            'mentor_profile_id' => $somebodyElse->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $package = MentorshipPackage::query()->firstOrFail();

    expect($package->mentor_profile_id)->toBe($this->mentor->id)
        ->and($package->price_kobo)->toBe(1_500_000);
});

it('lets a mentor mark their own work finished, and only their own', function () {
    Filament::setCurrentPanel('mentor');

    $client = User::factory()->create();
    $package = MentorshipPackage::factory()->create(['mentor_profile_id' => $this->mentor->id]);

    $engagement = app(EngagementService::class)->request($client, $package);

    // Paid, so there is something to finish.
    $invoice = $engagement->nextInvoice();
    app(EngagementService::class)->markInvoicePaid($invoice);

    $this->actingAs($this->mentor->user);

    livewire(ListEngagements::class)
        ->assertCanSeeTableRecords([$engagement])
        ->callAction(TestAction::make('markComplete')->table($engagement->fresh()));

    expect($engagement->fresh()->status)->toBe(EngagementStatus::AwaitingConfirmation);

    // Another mentor cannot even see it.
    $other = MentorProfile::factory()->approved()->create();
    $other->user->assignRole(RoleName::Mentor->value);

    $this->actingAs($other->user);

    livewire(ListEngagements::class)->assertCanNotSeeTableRecords([$engagement]);
});

it('shows a mentor their own reviews, including the ones still being moderated', function () {
    Filament::setCurrentPanel('mentor');

    $client = User::factory()->create();
    $package = MentorshipPackage::factory()->create(['mentor_profile_id' => $this->mentor->id]);

    $engagement = app(EngagementService::class)->request($client, $package);
    $engagement->forceFill(['status' => EngagementStatus::Completed, 'completed_at' => now()])->save();

    app(ReviewService::class)->leave($engagement->fresh(), $client, 2, 'Turned up late twice');

    $this->actingAs($this->mentor->user);

    // Nobody should learn their rating dropped by noticing the number on their
    // own public profile.
    livewire(ListReviews::class)
        ->assertOk()
        ->assertSee('Turned up late twice');
});
