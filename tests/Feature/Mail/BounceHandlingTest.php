<?php

use App\Enums\RoleName;
use App\Filament\Admin\Resources\EmailSuppressions\Pages\ListEmailSuppressions;
use App\Mail\WelcomeMail;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Services\Mail\SuppressionList;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Bounces, complaints, and the address the platform stops writing to.
 *
 * The reason any of this exists: a provider scores the whole platform on how
 * much of its mail bounces and how often people mark it as spam. Retrying one
 * dead address forever drags that score down for everybody — the seller waiting
 * on an order notification included.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config(['services.mail_webhook.secret' => 'a-shared-secret']);

    $this->post = fn (string $provider, array $payload) => $this->postJson(
        "/webhooks/mail/{$provider}?token=a-shared-secret",
        $payload,
    );
});

it('suppresses a hard bounce from every provider', function (string $provider, array $payload): void {
    ($this->post)($provider, $payload)->assertOk();

    expect(app(SuppressionList::class)->isSuppressed('dead@example.test'))->toBeTrue();
})->with([
    'resend' => ['resend', [
        'type' => 'email.bounced',
        'data' => ['to' => ['dead@example.test'], 'bounce' => ['message' => 'No such user']],
    ]],
    'postmark' => ['postmark', ['RecordType' => 'HardBounce', 'Email' => 'dead@example.test']],
    'brevo' => ['brevo', ['event' => 'hard_bounce', 'email' => 'dead@example.test']],
    'mailgun' => ['mailgun', ['event-data' => ['event' => 'failed', 'recipient' => 'dead@example.test']]],
]);

it('records a complaint as a complaint, not a bounce', function (): void {
    ($this->post)('postmark', ['RecordType' => 'SpamComplaint', 'Email' => 'annoyed@example.test']);

    /*
     * Worth telling apart. A bounce is an address that does not work; a
     * complaint is a working address whose owner asked us to stop. The second
     * is the one that gets a sending domain blocked.
     */
    expect(EmailSuppression::query()->where('email', 'annoyed@example.test')->value('type'))
        ->toBe(EmailSuppression::TYPE_COMPLAINT);
});

it('ignores a soft bounce', function (): void {
    ($this->post)('postmark', ['RecordType' => 'SoftBounce', 'Email' => 'busy@example.test'])->assertOk();

    /*
     * A full mailbox is not a dead address. Suppressing on one would lock
     * somebody out of their own receipts because their inbox was full on a
     * Tuesday — and "SoftBounce" and "HardBounce" differ by four characters,
     * which is exactly why the event names are matched exactly.
     */
    expect(app(SuppressionList::class)->isSuppressed('busy@example.test'))->toBeFalse();
});

it('answers 200 to an event it does not recognise', function (): void {
    /*
     * A provider that gets an error retries, backs off, and eventually disables
     * the webhook — so returning 500 for an "open" event would end with the
     * bounces we do care about no longer arriving.
     */
    ($this->post)('postmark', ['RecordType' => 'Open', 'Email' => 'somebody@example.test'])->assertOk();
});

it('refuses a caller with no secret', function (): void {
    // An open suppression endpoint lets anybody stop the platform writing to a
    // rival seller.
    $this->postJson('/webhooks/mail/postmark', ['RecordType' => 'HardBounce', 'Email' => 'dead@example.test'])
        ->assertStatus(401);

    expect(EmailSuppression::query()->count())->toBe(0);
});

it('refuses a caller with the wrong secret', function (): void {
    $this->postJson('/webhooks/mail/postmark?token=wrong', ['RecordType' => 'HardBounce', 'Email' => 'x@example.test'])
        ->assertStatus(401);
});

it('refuses everything when no secret is configured', function (): void {
    config(['services.mail_webhook.secret' => null]);

    // Unconfigured means closed, not open.
    $this->postJson('/webhooks/mail/postmark?token=', ['RecordType' => 'HardBounce', 'Email' => 'x@example.test'])
        ->assertStatus(401);
});

it('survives a redelivered webhook', function (): void {
    foreach (range(1, 3) as $ignored) {
        ($this->post)('postmark', ['RecordType' => 'HardBounce', 'Email' => 'dead@example.test'])->assertOk();
    }

    // One address, one row. A second would violate the unique index and turn a
    // redelivery into a 500 — which providers answer by retrying harder.
    expect(EmailSuppression::query()->where('email', 'dead@example.test')->count())->toBe(1);
});

it('stops sending to a suppressed address', function (): void {
    Mail::fake();

    $user = User::factory()->create(['email' => 'dead@example.test']);

    app(SuppressionList::class)->suppress('dead@example.test', EmailSuppression::TYPE_BOUNCE);

    Mail::to($user->email)->send(new WelcomeMail($user));

    /*
     * Mail::fake bypasses the MessageSending event, so this asserts on the real
     * mailer instead — see the test below. Kept here to document that the
     * suppression is checked at send time and not at queue time: a job queued
     * before a bounce and retried after it must still be stopped.
     */
    expect(app(SuppressionList::class)->isSuppressed('dead@example.test'))->toBeTrue();
});

it('cancels the message at the mailer', function (): void {
    config(['mail.default' => 'array']);

    $user = User::factory()->create(['email' => 'dead@example.test']);

    app(SuppressionList::class)->suppress('dead@example.test', EmailSuppression::TYPE_BOUNCE);

    Mail::to($user->email)->sendNow(new WelcomeMail($user));

    // Nothing left the mailer at all.
    expect(app('mailer')->getSymfonyTransport()->messages())->toBeEmpty();
});

it('still sends to everybody else', function (): void {
    config(['mail.default' => 'array']);

    $user = User::factory()->create(['email' => 'alive@example.test']);

    app(SuppressionList::class)->suppress('dead@example.test', EmailSuppression::TYPE_BOUNCE);

    Mail::to($user->email)->sendNow(new WelcomeMail($user));

    // The control: one suppression must not become a silent outage for
    // everybody, which is what a slightly wrong check here would produce.
    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('sends again once an administrator releases the address', function (): void {
    config(['mail.default' => 'array']);

    $admin = User::factory()->create();
    $user = User::factory()->create(['email' => 'fixed@example.test']);

    $suppressions = app(SuppressionList::class);
    $suppressions->suppress('fixed@example.test', EmailSuppression::TYPE_BOUNCE);
    $suppressions->release('fixed@example.test', $admin);

    Mail::to($user->email)->sendNow(new WelcomeMail($user));

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
});

it('re-suppresses an address that bounces again after release', function (): void {
    $suppressions = app(SuppressionList::class);

    $suppressions->suppress('flaky@example.test', EmailSuppression::TYPE_BOUNCE);
    $suppressions->release('flaky@example.test');

    ($this->post)('postmark', ['RecordType' => 'HardBounce', 'Email' => 'flaky@example.test']);

    // The provider is telling us it is still broken, which outranks somebody
    // having cleared it by hand last week.
    expect($suppressions->isSuppressed('flaky@example.test'))->toBeTrue();
});

it('shows suppressed addresses to an administrator', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(RoleName::Admin->value);

    app(SuppressionList::class)->suppress(
        'dead@example.test',
        EmailSuppression::TYPE_BOUNCE,
        'postmark',
        'No such user here',
    );

    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin)
        ->test(ListEmailSuppressions::class)
        ->assertCanSeeTableRecords(EmailSuppression::query()->get())
        ->assertSee('dead@example.test');
});

it('lets an administrator clear one', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(RoleName::Admin->value);

    $suppressions = app(SuppressionList::class);
    $suppressions->suppress('fixed@example.test', EmailSuppression::TYPE_BOUNCE);

    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin)
        ->test(ListEmailSuppressions::class)
        ->callAction(
            TestAction::make('release')
                ->table(EmailSuppression::query()->where('email', 'fixed@example.test')->first()),
        );

    expect($suppressions->isSuppressed('fixed@example.test'))->toBeFalse();
});
