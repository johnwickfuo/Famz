<?php

use App\Enums\RoleName;
use App\Enums\SellerStatus;
use App\Filament\Admin\Resources\SellerProfiles\Pages\EditSellerProfile;
use App\Filament\Admin\Resources\SellerProfiles\Pages\ListSellerProfiles;
use App\Mail\SellerApprovedMail;
use App\Mail\SellerMoreInfoMail;
use App\Mail\SellerRejectedMail;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);

    $this->actingAs($this->admin);
});

it('lists seller applications to an administrator', function () {
    SellerProfile::factory()->count(3)->create();

    $this->get('/admin/seller-profiles')->assertOk();
});

it('keeps a seller out of the seller administration screen', function () {
    $seller = User::factory()->create();
    $seller->assignRole(RoleName::Seller->value);

    $this->actingAs($seller)->get('/admin/seller-profiles')->assertForbidden();
});

it('approves an application from the table, granting the role and emailing them', function () {
    Mail::fake();

    $application = SellerProfile::factory()->create();

    livewire(ListSellerProfiles::class)
        ->callAction(TestAction::make('approve')->table($application))
        ->assertHasNoActionErrors();

    $application->refresh();

    expect($application->status)->toBe(SellerStatus::Approved)
        ->and($application->reviewed_by)->toBe($this->admin->id)
        ->and($application->user->fresh()->hasRole(RoleName::Seller->value))->toBeTrue();

    Mail::assertQueued(SellerApprovedMail::class);
});

it('will not reject an application without a reason', function () {
    Mail::fake();

    $application = SellerProfile::factory()->create();

    livewire(ListSellerProfiles::class)
        ->callAction(TestAction::make('reject')->table($application), ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($application->fresh()->status)->toBe(SellerStatus::Pending);
    Mail::assertNothingQueued();
});

it('rejects with a reason and passes it on to the applicant', function () {
    Mail::fake();

    $application = SellerProfile::factory()->create();

    livewire(ListSellerProfiles::class)
        ->callAction(TestAction::make('reject')->table($application), ['reason' => 'The ID document does not match the business name.'])
        ->assertHasNoActionErrors();

    $application->refresh();

    expect($application->status)->toBe(SellerStatus::Rejected)
        ->and($application->review_notes)->toBe('The ID document does not match the business name.');

    Mail::assertQueued(SellerRejectedMail::class);
});

it('asks for more information without closing the application', function () {
    Mail::fake();

    $application = SellerProfile::factory()->create();

    livewire(ListSellerProfiles::class)
        ->callAction(TestAction::make('requestMoreInformation')->table($application), ['notes' => 'Please upload a clearer photograph of your ID.'])
        ->assertHasNoActionErrors();

    $application->refresh();

    expect($application->status)->toBe(SellerStatus::NeedsMoreInfo)
        ->and($application->status->isOpenToApplicant())->toBeTrue();

    Mail::assertQueued(SellerMoreInfoMail::class);
});

it('offers the review actions on the edit page too', function () {
    $application = SellerProfile::factory()->create();

    livewire(EditSellerProfile::class, ['record' => $application->getRouteKey()])
        ->assertActionExists('approve')
        ->assertActionExists('reject')
        ->assertActionExists('requestMoreInformation');
});

it('does not offer approval on an already approved seller', function () {
    $application = SellerProfile::factory()->approved()->create();

    livewire(EditSellerProfile::class, ['record' => $application->getRouteKey()])
        ->assertActionHidden('approve');
});

it('lets an administrator vouch for a seller so their listings skip review', function () {
    $application = SellerProfile::factory()->approved()->create();

    livewire(EditSellerProfile::class, ['record' => $application->getRouteKey()])
        ->fillForm(['auto_approve_products' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($application->fresh()->skipsProductReview())->toBeTrue();
});

it('has no create screen, because a seller comes from somebody applying', function () {
    $this->get('/admin/seller-profiles/create')->assertNotFound();
});
