<?php

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Enums\RoleName;
use App\Filament\Admin\Resources\Consultations\ConsultationResource;
use App\Filament\Admin\Resources\Consultations\Pages\ListConsultations;
use App\Filament\Admin\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Admin\Resources\Consultations\RelationManagers\ReportsRelationManager;
use App\Mail\ConsultationQuotedMail;
use App\Mail\ConsultationReportReadyMail;
use App\Models\Consultation;
use App\Models\User;
use App\Services\Consultations\ConsultationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

/**
 * The queue an administrator actually works from.
 *
 * The thing being tested is that the promise is visible and actionable: late
 * work is findable, ringing somebody stops the clock, and quoting reaches the
 * client.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
    $this->actingAs($this->admin);

    $this->consultations = app(ConsultationService::class);

    $this->book = function (array $overrides = []): Consultation {
        return $this->consultations->book(array_merge([
            'full_name' => 'Chidi Farmer',
            'phone' => '08120000000',
            'email' => 'farmer@example.test',
            'tier' => 'standard',
        ], $overrides));
    };
});

it('opens on the late tab when anything is late', function () {
    $late = ($this->book)();

    $this->travelTo($late->response_due_at->copy()->addHour());

    expect(livewire(ListConsultations::class)->instance()->getDefaultActiveTab())->toBe('late');
});

it('opens on the queue when nothing is late', function () {
    ($this->book)();

    expect(livewire(ListConsultations::class)->instance()->getDefaultActiveTab())->toBe('queue');
});

it('lists only late consultations on the late tab', function () {
    $late = ($this->book)();
    $this->travelTo($late->response_due_at->copy()->addHour());

    $fresh = ($this->book)(['email' => 'other@example.test']);

    livewire(ListConsultations::class)
        ->set('activeTab', 'late')
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$fresh]);
});

it('drops a consultation off the late tab once somebody rings them', function () {
    $consultation = ($this->book)();
    $this->travelTo($consultation->response_due_at->copy()->addHour());

    livewire(ListConsultations::class)
        ->callAction(TestAction::make('recordContact')->table($consultation), ['note' => 'Spoke to him.']);

    $consultation->refresh();

    expect($consultation->first_responded_at)->not->toBeNull()
        ->and($consultation->status)->toBe(ConsultationStatus::Contacted)
        ->and($consultation->isOverdue())->toBeFalse();

    livewire(ListConsultations::class)
        ->set('activeTab', 'late')
        ->assertCanNotSeeTableRecords([$consultation]);
});

it('quotes from the queue and emails the client a link to pay', function () {
    $consultation = ($this->book)();

    livewire(ListConsultations::class)->callAction(
        TestAction::make('quote')->table($consultation),
        ['amount' => 35000, 'note' => 'Includes a farm visit.'],
    );

    $consultation->refresh();

    expect($consultation->quoted_amount_kobo)->toBe(3_500_000)
        ->and($consultation->status)->toBe(ConsultationStatus::Quoted)
        ->and($consultation->quoted_by)->toBe($this->admin->id);

    Mail::assertQueued(
        ConsultationQuotedMail::class,
        fn ($mail): bool => $mail->consultation->reference === $consultation->reference,
    );
});

it('counts only late consultations in the navigation badge', function () {
    $late = ($this->book)();

    expect(ConsultationResource::getNavigationBadge())->toBeNull();

    $this->travelTo($late->response_due_at->copy()->addHour());

    // Booked after the jump, so this one's own deadline is still ahead of it.
    ($this->book)(['email' => 'other@example.test']);

    // A badge that counts everything open is a number nobody reads after the
    // first week; one that is usually zero is a number people act on.
    expect(ConsultationResource::getNavigationBadge())->toBe('1');
});

it('shows an urgent consultation as urgent', function () {
    $urgent = ($this->book)(['tier' => 'urgent']);

    expect($urgent->tier)->toBe(ConsultationTier::Urgent);

    livewire(ListConsultations::class)
        ->assertCanSeeTableRecords([$urgent])
        ->assertSee('Urgent');
});

it('publishes a report from the relation manager and emails the client', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);

    $report = $consultation->reports()->create([
        'title' => 'Brooder losses',
        'findings' => 'Running four degrees too cold at night.',
        'recommendations' => 'Add a second heat source.',
    ]);

    livewire(ReportsRelationManager::class, [
        'ownerRecord' => $consultation,
        'pageClass' => ViewConsultation::class,
    ])->callAction(TestAction::make('publish')->table($report));

    expect($report->fresh()->isPublished())->toBeTrue();

    Mail::assertQueued(ConsultationReportReadyMail::class);
});

it('stamps the author when an administrator writes a report', function () {
    $consultation = ($this->book)();

    livewire(ReportsRelationManager::class, [
        'ownerRecord' => $consultation,
        'pageClass' => ViewConsultation::class,
    ])
        ->callAction(TestAction::make('create')->table(), [
            'title' => 'Brooder losses',
            'findings' => 'Running cold.',
            'recommendations' => 'More heat.',
        ]);

    $report = $consultation->reports()->firstOrFail();

    expect($report->created_by)->toBe($this->admin->id)
        // Written, not sent. Publishing is its own deliberate act.
        ->and($report->isPublished())->toBeFalse();
});

it('renders the consultation view page', function () {
    $consultation = ($this->book)(['situation' => 'Ten birds a day.']);

    livewire(ViewConsultation::class, ['record' => $consultation->getRouteKey()])
        ->assertOk()
        ->assertSee('Ten birds a day')
        // Guests are normal here, so it says so rather than showing a gap.
        ->assertSee('Booked as a guest');
});
