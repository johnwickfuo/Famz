<?php

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Mail\ConsultationBookedMail;
use App\Models\Consultation;
use App\Models\User;
use App\Services\Consultations\ConsultationService;
use App\Services\Consultations\ResponseClock;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Booking a consultation.
 *
 * The design constraint being tested is a business one: a farmer whose birds
 * are dying has to be able to send this in thirty seconds, from a phone, with
 * no account. Every test here is some version of that.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->consultations = app(ConsultationService::class);

    $this->minimal = [
        'full_name' => 'Ibrahim Sule',
        'phone' => '08031234567',
        'email' => 'ibrahim@example.test',
        'tier' => 'standard',
    ];
});

it('takes a booking with nothing but a name, a number, an email and a tier', function () {
    $this->post(route('consultations.store'), $this->minimal)
        ->assertRedirect();

    $consultation = Consultation::query()->firstOrFail();

    expect($consultation->full_name)->toBe('Ibrahim Sule')
        ->and($consultation->status)->toBe(ConsultationStatus::Submitted)
        // Everything else is genuinely optional, not silently defaulted to
        // something that will mislead whoever picks up the phone.
        ->and($consultation->situation)->toBeNull()
        ->and($consultation->category)->toBeNull()
        ->and($consultation->flock_size)->toBeNull()
        ->and($consultation->attachments)->toBeNull()
        // And no price. The whole flow is book first, pay later.
        ->and($consultation->quoted_amount_kobo)->toBeNull();
});

it('refuses a booking missing any of the four things it actually needs', function (string $field) {
    $this->post(route('consultations.store'), [...$this->minimal, $field => ''])
        ->assertSessionHasErrors($field);

    expect(Consultation::query()->count())->toBe(0);
})->with(['full_name', 'phone', 'email', 'tier']);

it('lets a guest book without an account', function () {
    $this->post(route('consultations.store'), $this->minimal)->assertRedirect();

    $consultation = Consultation::query()->firstOrFail();

    // The point of the whole thing: no account, and it still exists.
    expect($consultation->user_id)->toBeNull()
        ->and(User::query()->count())->toBe(0);
});

it('lets the guest who booked it read it back from the same browser', function () {
    $this->post(route('consultations.store'), $this->minimal);

    $consultation = Consultation::query()->firstOrFail();

    $this->get(route('consultations.show', $consultation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Consultations/Show'));
});

it('keeps a consultation away from a different browser that has the reference', function () {
    $this->post(route('consultations.store'), $this->minimal);

    $consultation = Consultation::query()->firstOrFail();

    // Fresh session: no booking in it, and no matching account.
    $this->flushSession();

    $this->get(route('consultations.show', $consultation))->assertForbidden();
});

it('emails the reference, because for a guest that email is the record', function () {
    $this->post(route('consultations.store'), $this->minimal);

    $consultation = Consultation::query()->firstOrFail();

    Mail::assertQueued(
        ConsultationBookedMail::class,
        fn ($mail): bool => $mail->consultation->reference === $consultation->reference,
    );
});

it('computes the response deadline from the tier and the settings', function () {
    $this->settings->set('consultation_standard_response_hours', '48', 'int', 'platform');

    $this->travelTo(now()->setTime(10, 0));

    $this->post(route('consultations.store'), $this->minimal);

    $consultation = Consultation::query()->firstOrFail();

    expect($consultation->response_due_at->toDateTimeString())
        ->toBe(now()->addHours(48)->toDateTimeString());
});

it('follows the settings when the standard window changes', function () {
    // A different number in settings has to produce a different promise, or
    // the setting is decoration.
    $this->settings->set('consultation_standard_response_hours', '12', 'int', 'platform');

    $this->travelTo(now()->setTime(10, 0));

    $this->post(route('consultations.store'), $this->minimal);

    expect(Consultation::query()->firstOrFail()->response_due_at->toDateTimeString())
        ->toBe(now()->addHours(12)->toDateTimeString());
});

it('counts urgent in working hours, so an overnight booking is not late on arrival', function () {
    $this->settings->set('consultation_urgent_response_hours', '6', 'int', 'platform');
    $this->settings->set('consultation_working_hours_start', '8', 'int', 'platform');
    $this->settings->set('consultation_working_hours_end', '17', 'int', 'platform');

    // Nine at night on a Tuesday.
    $this->travelTo(now()->startOfWeek()->addDay()->setTime(21, 0));

    $this->post(route('consultations.store'), [...$this->minimal, 'tier' => 'urgent']);

    $due = Consultation::query()->firstOrFail()->response_due_at;

    // The clock starts when the office opens, so six working hours lands at
    // two the following afternoon — not three in the morning.
    expect($due->format('H:i'))->toBe('14:00')
        ->and($due->isSameDay(now()->addDay()))->toBeTrue();
});

it('does not start the urgent clock on a closed day', function () {
    $this->settings->set('consultation_working_days', '1,2,3,4,5', 'string', 'platform');
    $this->settings->set('consultation_urgent_response_hours', '6', 'int', 'platform');

    // Saturday afternoon, with the office closed at weekends.
    $this->travelTo(now()->startOfWeek()->addDays(5)->setTime(14, 0));

    $this->post(route('consultations.store'), [...$this->minimal, 'tier' => 'urgent']);

    $due = Consultation::query()->firstOrFail()->response_due_at;

    expect($due->dayOfWeekIso)->toBe(1)
        ->and($due->format('H:i'))->toBe('14:00');
});

it('stores the deadline rather than deriving it, so changing settings does not rewrite history', function () {
    $this->settings->set('consultation_standard_response_hours', '48', 'int', 'platform');

    $this->post(route('consultations.store'), $this->minimal);

    $consultation = Consultation::query()->firstOrFail();
    $promised = $consultation->response_due_at->toDateTimeString();

    // The company shortens its promise next month.
    $this->settings->set('consultation_standard_response_hours', '6', 'int', 'platform');

    // Last week's booking keeps the promise it was actually given.
    expect($consultation->fresh()->response_due_at->toDateTimeString())->toBe($promised);
});

it('takes photographs and files them off the public form', function () {
    Storage::fake('public');

    $this->post(route('consultations.store'), [
        ...$this->minimal,
        'photos' => [
            UploadedFile::fake()->image('sick-bird.jpg'),
            UploadedFile::fake()->image('the-pen.jpg'),
        ],
    ])->assertRedirect();

    $consultation = Consultation::query()->firstOrFail();

    expect($consultation->attachments)->toHaveCount(2);

    foreach ($consultation->attachments as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

it('attaches a signed-in user to their own booking', function () {
    $user = User::factory()->create(['email' => 'ibrahim@example.test']);

    $this->actingAs($user)->post(route('consultations.store'), $this->minimal);

    expect(Consultation::query()->firstOrFail()->user_id)->toBe($user->id);
});

it('claims guest bookings when somebody registers with the same email', function () {
    // Booked at two in the morning with no account.
    $this->post(route('consultations.store'), $this->minimal);

    $consultation = Consultation::query()->firstOrFail();

    expect($consultation->user_id)->toBeNull();

    // They register the next day.
    $this->post(route('register'), [
        'name' => 'Ibrahim Sule',
        'email' => 'ibrahim@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $user = User::query()->where('email', 'ibrahim@example.test')->firstOrFail();

    expect($consultation->fresh()->user_id)->toBe($user->id);
});

it('does not claim a booking made with somebody else\'s email', function () {
    $this->post(route('consultations.store'), $this->minimal);

    $stranger = User::factory()->create(['email' => 'someone-else@example.test']);

    app(ConsultationService::class)->claimFor($stranger);

    expect(Consultation::query()->firstOrFail()->user_id)->toBeNull();
});

it('shows a claimed booking on the dashboard of the account that claimed it', function () {
    $this->post(route('consultations.store'), $this->minimal);

    $user = User::factory()->create(['email' => 'ibrahim@example.test']);

    $this->actingAs($user)
        ->get(route('consultations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('consultations', 1));
});

it('puts the tier promises from settings on the booking form', function () {
    $this->settings->set('consultation_standard_response_hours', '36', 'int', 'platform');
    $this->settings->set('consultation_urgent_response_hours', '4', 'int', 'platform');

    $this->get(route('consultations.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tiers.0.promise', 'Within 36 hours')
            ->where('tiers.1.promise', 'Within 4 working hours')
            ->where('tiers.1.is_premium', true));
});

it('reads back the promise the clock will actually keep', function () {
    // The form and the deadline must agree, so they are computed from the same
    // place rather than written out twice.
    $clock = app(ResponseClock::class);

    expect($clock->promiseFor(ConsultationTier::Standard))
        ->toContain((string) ConsultationTier::Standard->hours());
});
