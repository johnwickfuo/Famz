<?php

namespace App\Mail;

use App\Models\MentorshipEngagement;
use Illuminate\Mail\Mailables\Content;

/**
 * A mentor has accepted, and the contact details are now unlocked.
 *
 * That unlocking is the whole point of the message. Until a mentor confirms,
 * neither side can see how to reach the other; this is the email that says the
 * wall has come down and who is on the other side of it.
 */
class EngagementConfirmedMail extends BrandedMailable
{
    public function __construct(
        public readonly MentorshipEngagement $engagement,
        public readonly bool $forMentor = false,
        public readonly string $actionUrl = '/',
    ) {}

    protected function subjectLine(): string
    {
        return $this->forMentor
            ? __('You confirmed a mentorship — :reference', ['reference' => $this->engagement->reference])
            : __('Your mentor has confirmed — :reference', ['reference' => $this->engagement->reference]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.engagement-confirmed',
            with: [
                ...$this->layoutData(),
                'engagement' => $this->engagement,
                'forMentor' => $this->forMentor,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }
}
