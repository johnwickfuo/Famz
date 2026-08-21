<?php

use App\Enums\RoleName;
use App\Mail\MentorInvitationMail;
use App\Models\User;
use App\Services\Mentorship\InvitationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
});

it('emails an invitation with the join link', function (): void {
    Mail::fake();

    app(InvitationService::class)->create(
        $this->admin,
        email: 'a-mentor@example.test',
        name: 'A Mentor',
    );

    /*
     * There is no public mentor signup, so this link is the entire route in. An
     * invitation created and never delivered is a mentor who never joins —
     * which, before this, is what happened unless an administrator remembered
     * to copy the link out of the panel by hand.
     */
    // Queued rather than sent: every BrandedMailable is ShouldQueue, and they
    // run on their own `mail` queue so a slow provider cannot block a payment.
    Mail::assertQueued(MentorInvitationMail::class, function (MentorInvitationMail $mail): bool {
        return $mail->hasTo('a-mentor@example.test')
            && str_contains($mail->actionUrl, 'signature=');
    });
});

it('sends nothing when the invitation has no address', function (): void {
    Mail::fake();

    // An administrator may still mint an open link to send over WhatsApp, which
    // is why the address is nullable at all.
    app(InvitationService::class)->create($this->admin);

    Mail::assertNothingQueued();
});

it('still creates the invitation when the email cannot be sent', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('the mail provider is down'));

    $invitation = app(InvitationService::class)->create(
        $this->admin,
        email: 'a-mentor@example.test',
    );

    // The link still works and can be sent by hand, so losing the email must
    // not lose the invitation with it.
    expect($invitation->exists)->toBeTrue()
        ->and($invitation->token)->not->toBeEmpty();
});
