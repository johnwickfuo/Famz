<?php

use App\Enums\ConsultationStatus;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Consultations\ConsultationCheckout;
use App\Services\Consultations\ConsultationService;
use App\Services\Payments\PaymentProcessor;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * What happens after somebody books: the call, the price, the money, the
 * report, and the conversation afterwards.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    $this->settings = app(SettingsService::class);
    $this->consultations = app(ConsultationService::class);
    $this->checkout = app(ConsultationCheckout::class);

    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->admin->assignRole(RoleName::Admin->value);

    $this->client = User::factory()->create(['email' => 'farmer@example.test']);
    $this->client->profile()->create(['display_name' => 'Chidi Farmer', 'phone' => '08120000000']);

    $this->book = function (array $overrides = []): Consultation {
        return $this->consultations->book(array_merge([
            'full_name' => 'Chidi Farmer',
            'phone' => '08120000000',
            'email' => 'farmer@example.test',
            'tier' => 'standard',
            'situation' => 'Losing ten birds a day in a 2,000 layer house.',
        ], $overrides), $this->client);
    };

    $this->payFor = function (Consultation $consultation): Order {
        $order = $this->checkout->begin($this->client, $consultation);

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => random_int(1000, 99999),
                    'reference' => $order->reference,
                    'status' => 'success',
                    'amount' => $order->grand_total_kobo,
                    'currency' => 'NGN',
                    'paid_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);

        return $order->refresh();
    };
});

// ---------------------------------------------------------------------------
// The promise
// ---------------------------------------------------------------------------

it('flags a consultation as overdue once the promised time has passed', function () {
    $consultation = ($this->book)();

    expect($consultation->isOverdue())->toBeFalse();

    $this->travelTo($consultation->response_due_at->copy()->addMinute());

    expect($consultation->fresh()->isOverdue())->toBeTrue();
});

it('stops calling it overdue the moment somebody makes contact', function () {
    $consultation = ($this->book)();

    $this->travelTo($consultation->response_due_at->copy()->addDay());

    expect($consultation->fresh()->isOverdue())->toBeTrue();

    $this->consultations->recordContact($consultation->fresh(), $this->admin, 'Rang, no answer, left a message.');

    // The promise was to get back to them, and it has now been kept. A slow
    // week afterwards is a different problem from a broken promise.
    expect($consultation->fresh()->isOverdue())->toBeFalse()
        ->and($consultation->fresh()->status)->toBe(ConsultationStatus::Contacted);
});

it('records only the FIRST response, so a second call does not rewrite the first', function () {
    $consultation = ($this->book)();

    $this->consultations->recordContact($consultation, $this->admin);
    $first = $consultation->fresh()->first_responded_at;

    $this->travel(3)->hours();
    $this->consultations->recordContact($consultation->fresh(), $this->admin, 'Called again.');

    expect($consultation->fresh()->first_responded_at->toDateTimeString())
        ->toBe($first->toDateTimeString());
});

it('keeps the admin note history rather than overwriting it', function () {
    $consultation = ($this->book)();

    $this->consultations->recordContact($consultation, $this->admin, 'First call, no answer.');
    $this->consultations->recordContact($consultation->fresh(), $this->admin, 'Second call, spoke to him.');

    $notes = $consultation->fresh()->admin_notes;

    // The next person to pick this up needs the history, not the latest line.
    expect($notes)->toContain('First call, no answer.')
        ->and($notes)->toContain('Second call, spoke to him.')
        ->and($notes)->toContain('Ada Admin');
});

it('shows a consultation as unpriced until somebody quotes it', function () {
    $consultation = ($this->book)();

    expect($consultation->isQuoted())->toBeFalse()
        ->and($consultation->quotedAmount())->toBeNull()
        ->and($consultation->awaitsPayment())->toBeFalse();
});

// ---------------------------------------------------------------------------
// The quote
// ---------------------------------------------------------------------------

it('quotes a price, which also counts as having responded', function () {
    $consultation = ($this->book)();

    $quoted = $this->consultations->quote($consultation, $this->admin, 3_500_000, 'Includes a farm visit.');

    expect($quoted->quoted_amount_kobo)->toBe(3_500_000)
        ->and($quoted->quoted_by)->toBe($this->admin->id)
        ->and($quoted->status)->toBe(ConsultationStatus::Quoted)
        ->and($quoted->awaitsPayment())->toBeTrue()
        // An administrator who quotes has plainly been in touch.
        ->and($quoted->first_responded_at)->not->toBeNull();
});

it('refuses a quote of nothing', function () {
    $consultation = ($this->book)();

    expect(fn () => $this->consultations->quote($consultation, $this->admin, 0))
        ->toThrow(RuntimeException::class);
});

it('will not requote something already paid for', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);
    ($this->payFor)($consultation->fresh());

    expect(fn () => $this->consultations->quote($consultation->fresh(), $this->admin, 5_000_000))
        ->toThrow(RuntimeException::class);
});

it('sends the whole amount to the platform when the quote is paid', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 3_500_000);

    $order = ($this->payFor)($consultation->fresh());

    $consultation->refresh();

    expect($consultation->isPaid())->toBeTrue()
        ->and($consultation->status)->toBe(ConsultationStatus::Paid)
        // The company did the work; there is nobody to split with and nothing
        // to hold from anybody.
        ->and($order->subOrders()->count())->toBe(0);

    $entries = WalletTransaction::query()->get();

    expect($entries)->toHaveCount(1);

    $entry = $entries->first();

    expect($entry->type)->toBe(LedgerType::ConsultationFee)
        ->and($entry->state)->toBe(LedgerState::Released)
        ->and($entry->user_id)->toBeNull()
        ->and($entry->amount_kobo)->toBe(3_500_000);

    // Not a commission, not a sale, not held.
    expect(WalletTransaction::query()->whereIn('type', [
        LedgerType::Commission,
        LedgerType::Sale,
        LedgerType::MentorshipEarning,
    ])->count())->toBe(0);
});

it('settles once however many times the webhook is delivered', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_500_000);

    $order = $this->checkout->begin($this->client, $consultation->fresh());

    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'id' => 4242,
                'reference' => $order->reference,
                'status' => 'success',
                'amount' => $order->grand_total_kobo,
                'currency' => 'NGN',
                'paid_at' => now()->toIso8601String(),
            ],
        ]),
    ]);

    $processor = app(PaymentProcessor::class);

    expect($processor->markPaidFromReference('paystack', $order->reference))->toBeTrue()
        ->and($processor->markPaidFromReference('paystack', $order->reference))->toBeFalse();

    expect(WalletTransaction::query()->count())->toBe(1);
});

it('reuses the unpaid order when somebody starts paying twice', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);

    $first = $this->checkout->begin($this->client, $consultation->fresh());
    $second = $this->checkout->begin($this->client, $consultation->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Order::query()->count())->toBe(1);
});

it('will not let somebody pay for a consultation that is not theirs', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);

    $stranger = User::factory()->create();

    expect(fn () => $this->checkout->begin($stranger, $consultation->fresh()))
        ->toThrow(RuntimeException::class);
});

it('will not start a payment on something with no price yet', function () {
    $consultation = ($this->book)();

    expect(fn () => $this->checkout->begin($this->client, $consultation))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// The report
// ---------------------------------------------------------------------------

it('keeps a draft report out of the client\'s hands entirely', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);
    ($this->payFor)($consultation->fresh());

    $report = $consultation->reports()->create([
        'title' => 'Brooder losses',
        'findings' => 'The house is running four degrees too cold at night.',
        'recommendations' => 'Add a second heat source and check it at 2am for three nights.',
    ]);

    expect($report->isPublished())->toBeFalse();

    $response = $this->actingAs($this->client)
        ->get(route('consultations.show', $consultation->fresh()))
        ->assertOk();

    // Absent, not hidden: the page has no report prop at all, so there is
    // nothing here for a front end to render by mistake.
    $response->assertInertia(fn ($page) => $page->where('report', null));
    $response->assertDontSee('four degrees too cold');

    // And no PDF either.
    $this->actingAs($this->client)
        ->get(route('consultations.report', $consultation->fresh()))
        ->assertNotFound();
});

it('shows the report the moment it is published', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);
    ($this->payFor)($consultation->fresh());

    $report = $consultation->reports()->create([
        'title' => 'Brooder losses',
        'findings' => 'The house is running four degrees too cold at night.',
        'recommendations' => 'Add a second heat source.',
    ]);

    $this->consultations->publish($report);

    $this->actingAs($this->client)
        ->get(route('consultations.show', $consultation->fresh()))
        ->assertOk()
        ->assertSee('four degrees too cold');

    $this->actingAs($this->client)
        ->get(route('consultations.report', $consultation->fresh()))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('refuses to publish a report with nothing in it', function () {
    $consultation = ($this->book)();

    $report = $consultation->reports()->create([
        'title' => 'Placeholder',
        'findings' => '   ',
        'recommendations' => '',
    ]);

    // Publishing an empty report tells the client something untrue about the
    // work that was done for them.
    expect(fn () => $this->consultations->publish($report))->toThrow(RuntimeException::class);

    expect($report->fresh()->isPublished())->toBeFalse();
});

it('can take a published report back down', function () {
    $consultation = ($this->book)();

    $report = $consultation->reports()->create([
        'title' => 'Brooder losses',
        'findings' => 'Findings.',
        'recommendations' => 'Recommendations.',
    ]);

    $this->consultations->publish($report);
    expect($consultation->fresh()->publishedReport)->not->toBeNull();

    $this->consultations->unpublish($report->fresh());
    expect($consultation->fresh()->publishedReport)->toBeNull();
});

// ---------------------------------------------------------------------------
// Follow-ups
// ---------------------------------------------------------------------------

it('opens follow-up once the work is paid for and not before', function () {
    $consultation = ($this->book)();

    expect($consultation->followupsOpen())->toBeFalse();

    expect(fn () => $this->consultations->followUp(
        $consultation,
        ConsultationFollowup::FROM_CLIENT,
        'Any news?',
        $this->client,
    ))->toThrow(RuntimeException::class);

    $this->consultations->quote($consultation, $this->admin, 1_000_000);
    ($this->payFor)($consultation->fresh());

    expect($consultation->fresh()->followupsOpen())->toBeTrue();
});

it('keeps follow-up open for the configured window after it finishes', function () {
    $this->settings->set('consultation_followup_days', '30', 'int', 'platform');

    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);
    ($this->payFor)($consultation->fresh());
    $this->consultations->complete($consultation->fresh());

    $this->travel(29)->days();
    expect($consultation->fresh()->followupsOpen())->toBeTrue();

    $this->travel(2)->days();
    expect($consultation->fresh()->followupsOpen())->toBeFalse();

    expect(fn () => $this->consultations->followUp(
        $consultation->fresh(),
        ConsultationFollowup::FROM_CLIENT,
        'One more thing',
        $this->client,
    ))->toThrow(RuntimeException::class);
});

it('lets the company write after the client window has closed', function () {
    $this->settings->set('consultation_followup_days', '7', 'int', 'platform');

    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);
    ($this->payFor)($consultation->fresh());
    $this->consultations->complete($consultation->fresh());

    $this->travel(10)->days();

    // Chasing somebody who has gone quiet is a legitimate thing to do.
    $note = $this->consultations->followUp(
        $consultation->fresh(),
        ConsultationFollowup::FROM_COMPANY,
        'Checking the birds picked up — let us know.',
        $this->admin,
    );

    expect($note->isFromCompany())->toBeTrue();
});

it('never shows an internal note to the client', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);
    ($this->payFor)($consultation->fresh());

    $this->consultations->followUp(
        $consultation->fresh(),
        ConsultationFollowup::FROM_COMPANY,
        'Client is difficult, bill carefully next time.',
        $this->admin,
        internal: true,
    );

    $this->consultations->followUp(
        $consultation->fresh(),
        ConsultationFollowup::FROM_COMPANY,
        'The vaccine arrives Thursday.',
        $this->admin,
    );

    $this->actingAs($this->client)
        ->get(route('consultations.show', $consultation->fresh()))
        ->assertOk()
        ->assertSee('vaccine arrives Thursday')
        ->assertDontSee('bill carefully');
});

it('keeps a consultation away from somebody who is not its client', function () {
    $consultation = ($this->book)();

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('consultations.show', $consultation))
        ->assertForbidden();

    $this->actingAs($this->client)
        ->get(route('consultations.show', $consultation))
        ->assertOk();
});

it('will not cancel something already paid for', function () {
    $consultation = ($this->book)();
    $this->consultations->quote($consultation, $this->admin, 1_000_000);
    ($this->payFor)($consultation->fresh());

    // Money has changed hands; that is a refund conversation, not a cancel.
    expect(fn () => $this->consultations->cancel($consultation->fresh(), 'Changed their mind'))
        ->toThrow(RuntimeException::class);
});
