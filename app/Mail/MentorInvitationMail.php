<?php

namespace App\Mail;

use App\Models\MentorInvitation;
use Illuminate\Mail\Mailables\Content;

/**
 * The only way to become a mentor on this platform.
 *
 * There is no public signup link — by design — so this email is not a
 * convenience, it is the entire onboarding route. Until it existed, an
 * administrator created an invitation and then had to copy the link out of the
 * admin panel and send it by hand.
 */
class MentorInvitationMail extends BrandedMailable
{
    public function __construct(
        public readonly MentorInvitation $invitation,
        public readonly string $actionUrl,
    ) {}

    protected function subjectLine(): string
    {
        return __('An invitation to mentor on {company}');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.mentor-invitation',
            with: [...$this->layoutData(), 'invitation' => $this->invitation, 'actionUrl' => $this->actionUrl],
        );
    }
}
