<?php

use App\Enums\BillingInterval;
use App\Enums\EngagementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\MentorProfile;
use App\Models\MentorshipEngagement;
use App\Models\MentorshipPackage;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Mentorship\EngagementService;
use App\Services\Mentorship\MentorshipCheckout;
use App\Services\Payments\PaymentProcessor;
use App\Services\Settings\SettingsService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SpecialisationSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Hiring a mentor, and what the money does.
 *
 * The two rules under test are the ones the whole product rests on: contact
 * details do not exist before payment, and the mentor's money does not move
 * before somebody confirms the work.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(SpecialisationSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->settings->set('mentorship_commission_percent', '15', 'float', 'platform');
    $this->settings->set('mentorship_confirmation_days', '7', 'int', 'platform');

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    $this->engagements = app(EngagementService::class);
    $this->checkout = app(MentorshipCheckout::class);

    $this->client = User::factory()->create(['name' => 'Ifeoma Nwosu']);
    $this->client->profile()->create(['display_name' => 'Ifeoma Nwosu', 'phone' => '08123456789']);

    $this->mentor = MentorProfile::factory()->approved()->create([
        'contact_value' => '08099998888',
    ]);

    $this->package = MentorshipPackage::factory()->pricedAt(2_000_000)->create([
        'mentor_profile_id' => $this->mentor->id,
        'title' => 'Farm visit and written report',
    ]);

    $this->paid = function (MentorshipEngagement $engagement): void {
        $invoice = $engagement->nextInvoice();
        $order = $this->checkout->begin($this->client, $invoice);

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 1,
                    'reference' => $order->reference,
                    'status' => 'success',
                    'amount' => $invoice->amount_kobo,
                    'currency' => 'NGN',
                    'paid_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        app(PaymentProcessor::class)->markPaidFromReference('paystack', $order->reference);
    };
});

it('snapshots the commercial terms so repricing the package changes nothing', function () {
    $engagement = $this->engagements->request($this->client, $this->package);

    $this->package->forceFill(['price_kobo' => 9_999_999, 'title' => 'Renamed'])->save();

    $engagement->refresh();

    expect($engagement->price_kobo)->toBe(2_000_000)
        ->and($engagement->package_title)->toBe('Farm visit and written report')
        ->and($engagement->commission_percent_snapshot)->toBe(15.0)
        // 15% of ₦20,000, and the mentor's share by subtraction.
        ->and($engagement->platform_amount_kobo)->toBe(300_000)
        ->and($engagement->mentor_amount_kobo)->toBe(1_700_000)
        ->and($engagement->platform_amount_kobo + $engagement->mentor_amount_kobo)
        ->toBe($engagement->price_kobo);
});

it('does not put contact details in any response before the money has cleared', function () {
    $engagement = $this->engagements->request($this->client, $this->package);

    $response = $this->actingAs($this->client)
        ->get(route('mentorship.show', $engagement))
        ->assertOk();

    // Absent, not hidden. The prop is null and the number appears nowhere in
    // the payload at all.
    $response->assertInertia(fn ($page) => $page->where('contact', null));
    $response->assertDontSee('08099998888');

    // Nor on the public profile, signed in or not.
    $this->actingAs($this->client)
        ->get(route('mentors.show', $this->mentor))
        ->assertOk()
        ->assertDontSee('08099998888');

    $this->get(route('mentors.show', $this->mentor))
        ->assertOk()
        ->assertDontSee('08099998888');
});

it('reveals both sides the moment the payment is verified', function () {
    $engagement = $this->engagements->request($this->client, $this->package);

    ($this->paid)($engagement);

    $engagement->refresh();

    expect($engagement->status)->toBe(EngagementStatus::Active);

    $response = $this->actingAs($this->client)
        ->get(route('mentorship.show', $engagement))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->where('contact.value', '08099998888'));

    // And symmetrically: the mentor now has the client's number.
    expect($engagement->clientContact()['phone'])->toBe('08123456789');
});

it('will not hand out contact details for somebody else\'s engagement', function () {
    $engagement = $this->engagements->request($this->client, $this->package);
    ($this->paid)($engagement);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('mentorship.show', $engagement->fresh()))
        ->assertForbidden();

    // And at the model level, which is where the rule actually lives: an
    // engagement belonging to a different mentor unlocks nothing.
    $otherMentor = MentorProfile::factory()->approved()->create();

    expect($otherMentor->contactFor($engagement->fresh()))->toBeNull();
});

it('holds both the mentor share and the commission when the money arrives', function () {
    $engagement = $this->engagements->request($this->client, $this->package);

    ($this->paid)($engagement);

    $entries = WalletTransaction::query()->get();

    expect($entries)->toHaveCount(2);

    $mentorRow = $entries->firstWhere('type', LedgerType::MentorshipEarning);
    $platformRow = $entries->firstWhere('type', LedgerType::Commission);

    expect($mentorRow->state)->toBe(LedgerState::Held)
        ->and($mentorRow->user_id)->toBe($this->mentor->user_id)
        ->and($mentorRow->amount_kobo)->toBe(1_700_000)
        ->and($mentorRow->mentorship_invoice_id)->not->toBeNull()
        // The platform has not earned its cut either until the work is done.
        ->and($platformRow->state)->toBe(LedgerState::Held)
        ->and($platformRow->user_id)->toBeNull()
        ->and($platformRow->amount_kobo)->toBe(300_000);

    // Nothing spendable yet, for anybody.
    expect(app(WalletService::class)->availableBalance($this->mentor->user))->toBe(0);
});

it('keeps the payout held while the client has not confirmed', function () {
    $engagement = $this->engagements->request($this->client, $this->package);
    ($this->paid)($engagement);

    $this->engagements->markComplete($engagement->fresh(), $this->mentor->user);

    $engagement->refresh();

    expect($engagement->status)->toBe(EngagementStatus::AwaitingConfirmation)
        ->and($engagement->auto_confirm_at)->not->toBeNull()
        // Marking it done is not being paid.
        ->and(app(WalletService::class)->availableBalance($this->mentor->user))->toBe(0)
        ->and(app(WalletService::class)->heldBalance($this->mentor->user))->toBe(1_700_000);
});

it('releases the money when the client confirms', function () {
    $engagement = $this->engagements->request($this->client, $this->package);
    ($this->paid)($engagement);

    $this->engagements->markComplete($engagement->fresh(), $this->mentor->user);

    $this->actingAs($this->client)
        ->post(route('mentorship.confirm', $engagement->fresh()))
        ->assertRedirect();

    $wallet = app(WalletService::class);

    expect($wallet->availableBalance($this->mentor->user))->toBe(1_700_000)
        ->and($wallet->heldBalance($this->mentor->user))->toBe(0)
        // The platform's commission clears at the same moment, not before.
        ->and($wallet->platformEarnings())->toBe(300_000);

    $engagement->refresh();

    expect($engagement->status)->toBe(EngagementStatus::Completed)
        ->and($engagement->auto_confirmed)->toBeFalse()
        ->and($engagement->invoices()->first()->status)->toBe(InvoiceStatus::Released)
        // Counted from the rows, not incremented.
        ->and($this->mentor->fresh()->engagements_completed)->toBe(1);
});

it('confirms by itself once the window has run out', function () {
    $engagement = $this->engagements->request($this->client, $this->package);
    ($this->paid)($engagement);

    $this->engagements->markComplete($engagement->fresh(), $this->mentor->user);

    // Six days: still the client's decision.
    $this->travel(6)->days();
    expect($this->engagements->autoConfirmDue())->toBe(0);
    expect($engagement->fresh()->status)->toBe(EngagementStatus::AwaitingConfirmation);

    // Eight: silence has now lasted long enough to count as agreement.
    $this->travel(2)->days();
    expect($this->engagements->autoConfirmDue())->toBe(1);

    $engagement->refresh();

    expect($engagement->status)->toBe(EngagementStatus::Completed)
        ->and($engagement->auto_confirmed)->toBeTrue()
        ->and(app(WalletService::class)->availableBalance($this->mentor->user))
        ->toBe(1_700_000);
});

it('refuses to mark complete on somebody else\'s engagement', function () {
    $engagement = $this->engagements->request($this->client, $this->package);
    ($this->paid)($engagement);

    $impostor = MentorProfile::factory()->approved()->create();

    expect(fn () => $this->engagements->markComplete($engagement->fresh(), $impostor->user))
        ->toThrow(RuntimeException::class);
});

it('refuses to mark complete before anything has been paid', function () {
    $engagement = $this->engagements->request($this->client, $this->package);

    expect(fn () => $this->engagements->markComplete($engagement, $this->mentor->user))
        ->toThrow(RuntimeException::class);
});

it('writes one set of entries however many times the webhook is delivered', function () {
    $engagement = $this->engagements->request($this->client, $this->package);
    $invoice = $engagement->nextInvoice();
    $order = $this->checkout->begin($this->client, $invoice);

    Http::fake([
        '*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'id' => 1,
                'reference' => $order->reference,
                'status' => 'success',
                'amount' => $invoice->amount_kobo,
                'currency' => 'NGN',
                'paid_at' => now()->toIso8601String(),
            ],
        ]),
    ]);

    $processor = app(PaymentProcessor::class);

    expect($processor->markPaidFromReference('paystack', $order->reference))->toBeTrue()
        ->and($processor->markPaidFromReference('paystack', $order->reference))->toBeFalse();

    expect(WalletTransaction::query()->count())->toBe(2);
});

it('bills a monthly engagement one period at a time', function () {
    $monthly = MentorshipPackage::factory()
        ->periodic(BillingInterval::Monthly)
        ->pricedAt(1_000_000)
        ->create(['mentor_profile_id' => $this->mentor->id]);

    $engagement = $this->engagements->request($this->client, $monthly);

    expect($engagement->invoices()->count())->toBe(1);

    ($this->paid)($engagement);

    // Mid-period: nothing new is owed.
    $this->travel(20)->days();
    expect($this->engagements->openDuePeriods())->toBe(0);

    // Past the period plus the grace days.
    $this->travel(15)->days();
    expect($this->engagements->openDuePeriods())->toBe(1);

    $engagement->refresh();

    expect($engagement->invoices()->count())->toBe(2)
        ->and($engagement->nextInvoice()->sequence)->toBe(2)
        // Month one is paid and still held; month two is not paid at all. The
        // mentor is paid per confirmed period, never upfront for the term.
        ->and($engagement->paidToDateKobo())->toBe(1_000_000);
});

it('sends a mentorship order through the ordinary payment layer with no sub-orders', function () {
    $engagement = $this->engagements->request($this->client, $this->package);
    $order = $this->checkout->begin($this->client, $engagement->nextInvoice());

    expect($order->grand_total_kobo)->toBe(2_000_000)
        ->and($order->subOrders()->count())->toBe(0)
        ->and(Order::query()->count())->toBe(1);
});

it('reuses the unpaid order when somebody starts paying twice', function () {
    $engagement = $this->engagements->request($this->client, $this->package);
    $invoice = $engagement->nextInvoice();

    $first = $this->checkout->begin($this->client, $invoice);
    $second = $this->checkout->begin($this->client, $invoice->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Order::query()->count())->toBe(1);
});

it('will not let somebody hire a mentor who is not approved', function () {
    $pending = MentorProfile::factory()->create();
    $package = MentorshipPackage::factory()->create(['mentor_profile_id' => $pending->id]);

    expect(fn () => $this->engagements->request($this->client, $package))
        ->toThrow(RuntimeException::class);
});

it('will not let a mentor hire themselves', function () {
    expect(fn () => $this->engagements->request($this->mentor->user, $this->package))
        ->toThrow(RuntimeException::class);
});

it('states the price with its period so a monthly fee is never read as a one-off', function () {
    $monthly = MentorshipPackage::factory()
        ->periodic(BillingInterval::Monthly)
        ->pricedAt(4_000_000)
        ->create(['mentor_profile_id' => $this->mentor->id]);

    expect($monthly->priceLabel())->toBe(Money::fromKobo(4_000_000).' a month')
        ->and($this->package->priceLabel())->toBe(Money::fromKobo(2_000_000));
});
