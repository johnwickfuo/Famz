<?php

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Enrolment;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Academy\CertificateIssuer;
use App\Services\Academy\EnrolmentService;
use App\Services\Payments\PaymentProcessor;
use App\Services\Settings\SettingsService;
use App\Services\Settlement\EscrowDriver;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Buying a course, and what the money does afterwards.
 *
 * The rule under test is short: the whole amount is the platform's. No seller,
 * no commission split, no escrow hold, no dispute window. It is the opposite of
 * every other payment in this application, so it is worth proving rather than
 * assuming — especially since it holds by the shape of the data (a course order
 * has no sub-orders) rather than by a branch anybody can see.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    // Escrow on, deliberately: if any of it leaked into a course sale, this is
    // the setting that would make it visible.
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->settings->set('commission_rate', '10', 'string', 'platform');

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    $this->student = User::factory()->create(['name' => 'Aisha Bello']);

    $this->course = Course::factory()->published()->create([
        'title' => 'Feed formulation on a small farm',
        'price_kobo' => 1_500_000,
        'is_free' => false,
    ]);

    $module = CourseModule::factory()->for($this->course)->create();
    CourseLesson::factory()->count(2)->create(['course_module_id' => $module->id]);

    $this->gatewayVerifies = function (Order $order, int $amountKobo): void {
        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 445566,
                    'reference' => $order->reference,
                    'status' => 'success',
                    'amount' => $amountKobo,
                    'currency' => 'NGN',
                    'paid_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    };

    $this->buy = function (array $overrides = []): TestResponse {
        return $this->actingAs($this->student)->post(
            route('academy.checkout.store', $this->course->slug),
            array_merge(['accept_terms' => true, 'gateway' => 'paystack'], $overrides),
        );
    };
});

it('refuses to sell a course without the all-sales-final tick', function () {
    $response = ($this->buy)(['accept_terms' => false]);

    $response->assertSessionHasErrors('accept_terms');

    expect(Order::query()->count())->toBe(0)
        ->and(Enrolment::query()->count())->toBe(0);
});

it('records the consent wording as it stood when they ticked it', function () {
    // The wording is what the argument will be about, so the text itself is
    // stored — not a version number pointing at wording that may have changed.
    Http::fake(['*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://pay.test/x']])]);

    ($this->buy)();

    $enrolment = Enrolment::query()->firstOrFail();

    expect($enrolment->terms_accepted)->toBeTrue()
        ->and($enrolment->terms_accepted_at)->not->toBeNull()
        ->and($enrolment->terms_accepted_text)->toBe(app(EnrolmentService::class)->termsText())
        ->and($enrolment->terms_accepted_text)->toContain('All sales are final')
        ->and($enrolment->terms_accepted_ip)->not->toBeNull();
});

it('does not open the course until the money has actually cleared', function () {
    Http::fake(['*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://pay.test/x']])]);

    ($this->buy)();

    $enrolment = Enrolment::query()->firstOrFail();

    expect($enrolment->enrolled_at)->toBeNull()
        ->and($enrolment->isActive())->toBeFalse();

    // And the player refuses, rather than letting somebody read a course they
    // have started paying for but not finished paying for.
    $this->actingAs($this->student)
        ->get(route('academy.player', $this->course->slug))
        ->assertRedirect(route('academy.course', $this->course->slug));
});

it('gives the whole amount to the platform with no commission split and no escrow', function () {
    Http::fake(['*/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://pay.test/x', 'reference' => 'x'],
    ])]);

    ($this->buy)();

    $order = Order::query()->firstOrFail();

    ($this->gatewayVerifies)($order, 1_500_000);

    expect(app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference))->toBeTrue();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        // The whole reason there is no split: there is nobody to split with.
        ->and($order->subOrders()->count())->toBe(0);

    $entries = WalletTransaction::query()->get();

    expect($entries)->toHaveCount(1);

    $entry = $entries->first();

    expect($entry->type)->toBe(LedgerType::CourseSale)
        // Released, not Held: escrow protects a buyer waiting on goods to
        // arrive, and there is nothing in transit here.
        ->and($entry->state)->toBe(LedgerState::Released)
        // The platform's own row carries no user.
        ->and($entry->user_id)->toBeNull()
        ->and($entry->amount_kobo)->toBe(1_500_000);

    // Not a commission row, not a sale row, not a payout row. Had any of the
    // marketplace settlement run, one of these would exist.
    expect(WalletTransaction::query()->whereIn('type', [
        LedgerType::Commission,
        LedgerType::Sale,
        LedgerType::Withdrawal,
    ])->count())->toBe(0);
});

it('opens the course the moment the payment clears', function () {
    Http::fake(['*/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://pay.test/x', 'reference' => 'x'],
    ])]);

    ($this->buy)();

    $order = Order::query()->firstOrFail();
    ($this->gatewayVerifies)($order, 1_500_000);

    app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);

    $enrolment = Enrolment::query()->firstOrFail();

    expect($enrolment->enrolled_at)->not->toBeNull()
        ->and($enrolment->isActive())->toBeTrue()
        ->and($enrolment->price_paid_kobo)->toBe(1_500_000);

    $this->actingAs($this->student)
        ->get(route('academy.player', $this->course->slug))
        ->assertOk();
});

it('does not enrol twice or write a second ledger row when the webhook is redelivered', function () {
    Http::fake(['*/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://pay.test/x', 'reference' => 'x'],
    ])]);

    ($this->buy)();

    $order = Order::query()->firstOrFail();
    ($this->gatewayVerifies)($order, 1_500_000);

    $processor = app(PaymentProcessor::class);

    expect($processor->markPaidFromReference('paystack', $order->reference))->toBeTrue()
        ->and($processor->markPaidFromReference('paystack', $order->reference))->toBeFalse();

    expect(Enrolment::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->count())->toBe(1);
});

it('lets somebody onto a free course without a gateway, and still takes the consent', function () {
    $free = Course::factory()->published()->create(['price_kobo' => 0, 'is_free' => true]);
    $module = CourseModule::factory()->for($free)->create();
    CourseLesson::factory()->create(['course_module_id' => $module->id]);

    $this->actingAs($this->student)
        ->post(route('academy.checkout.store', $free->slug), ['accept_terms' => true])
        ->assertRedirect(route('academy.player', $free->slug));

    $enrolment = Enrolment::query()->where('course_id', $free->id)->firstOrFail();

    expect($enrolment->isActive())->toBeTrue()
        ->and($enrolment->price_paid_kobo)->toBe(0)
        ->and($enrolment->terms_accepted)->toBeTrue()
        // Free means free: nothing to record in the books.
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(Order::query()->count())->toBe(0);
});

it('sends somebody who already owns the course to the player rather than selling it again', function () {
    app(EnrolmentService::class)->enrol($this->student, $this->course);

    $this->actingAs($this->student)
        ->get(route('academy.checkout', $this->course->slug))
        ->assertRedirect(route('academy.player', $this->course->slug));
});

it('will not sell a course that is not published', function () {
    $draft = Course::factory()->create();

    $this->actingAs($this->student)
        ->get(route('academy.checkout', $draft->slug))
        ->assertNotFound();
});

/*
 * The public check page. Aimed at an employer holding a printed certificate,
 * so it has to work with no account at all.
 */
it('resolves a certificate from its code with nobody signed in', function () {
    $enrolment = app(EnrolmentService::class)->enrol($this->student, $this->course);

    foreach ($this->course->lessons()->get() as $lesson) {
        app(EnrolmentService::class)->setCompleted($enrolment->fresh(), $lesson);
    }

    $certificate = app(CertificateIssuer::class)->issue($enrolment->fresh());

    $this->get(route('certificates.verify', $certificate->verification_code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Academy/Verify')
            ->where('certificate.holder', 'Aisha Bello')
            ->where('certificate.course', 'Feed formulation on a small farm')
            ->where('certificate.is_valid', true));
});

it('resolves the code however it was typed off the printed copy', function () {
    $enrolment = app(EnrolmentService::class)->enrol($this->student, $this->course);

    foreach ($this->course->lessons()->get() as $lesson) {
        app(EnrolmentService::class)->setCompleted($enrolment->fresh(), $lesson);
    }

    $certificate = app(CertificateIssuer::class)->issue($enrolment->fresh());

    // Somebody reading it aloud over the phone will not preserve the case.
    $this->get(route('certificates.verify', strtolower($certificate->verification_code)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('certificate.is_valid', true));
});

it('says plainly that an unknown code is not a certificate', function () {
    $this->get(route('certificates.verify', 'NOT-A-REAL-CODE'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Academy/Verify')
            ->where('certificate', null));
});

it('shows a withdrawn certificate as withdrawn rather than pretending it never existed', function () {
    $enrolment = app(EnrolmentService::class)->enrol($this->student, $this->course);

    foreach ($this->course->lessons()->get() as $lesson) {
        app(EnrolmentService::class)->setCompleted($enrolment->fresh(), $lesson);
    }

    $certificate = app(CertificateIssuer::class)->issue($enrolment->fresh());

    $certificate->forceFill([
        'revoked_at' => now(),
        'revocation_reason' => 'Issued to the wrong person.',
    ])->save();

    $this->get(route('certificates.verify', $certificate->verification_code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('certificate.is_valid', false)
            ->where('certificate.revoked_reason', 'Issued to the wrong person.'));
});

it('never puts the email of the holder on the public check page', function () {
    $enrolment = app(EnrolmentService::class)->enrol($this->student, $this->course);

    foreach ($this->course->lessons()->get() as $lesson) {
        app(EnrolmentService::class)->setCompleted($enrolment->fresh(), $lesson);
    }

    $certificate = app(CertificateIssuer::class)->issue($enrolment->fresh());

    // The page proves a certificate is real. It is not a way to look somebody
    // up, so nothing about them beyond the printed name may appear.
    $this->get(route('certificates.verify', $certificate->verification_code))
        ->assertOk()
        ->assertDontSee($this->student->email);
});

it('keeps a certificate readable to its holder and nobody else', function () {
    $enrolment = app(EnrolmentService::class)->enrol($this->student, $this->course);

    foreach ($this->course->lessons()->get() as $lesson) {
        app(EnrolmentService::class)->setCompleted($enrolment->fresh(), $lesson);
    }

    $certificate = app(CertificateIssuer::class)->issue($enrolment->fresh());
    $somebodyElse = User::factory()->create();

    $this->actingAs($this->student)
        ->get(route('academy.certificate.show', $certificate->verification_code))
        ->assertOk();

    $this->actingAs($somebodyElse)
        ->get(route('academy.certificate.show', $certificate->verification_code))
        ->assertForbidden();

    $this->actingAs($somebodyElse)
        ->get(route('academy.certificate.pdf', $certificate->verification_code))
        ->assertForbidden();
});

it('refuses to reprint a withdrawn certificate', function () {
    $enrolment = app(EnrolmentService::class)->enrol($this->student, $this->course);

    foreach ($this->course->lessons()->get() as $lesson) {
        app(EnrolmentService::class)->setCompleted($enrolment->fresh(), $lesson);
    }

    $certificate = app(CertificateIssuer::class)->issue($enrolment->fresh());

    $certificate->forceFill(['revoked_at' => now()])->save();

    $this->actingAs($this->student)
        ->get(route('academy.certificate.pdf', $certificate->verification_code))
        ->assertStatus(410);

    expect(Certificate::query()->count())->toBe(1);
});
