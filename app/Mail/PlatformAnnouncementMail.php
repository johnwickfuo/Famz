<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;

/**
 * The one non-transactional mail class: an administrator writes the copy, and
 * it goes out with an unsubscribe link. {company} and {company_short} in the
 * authored body are expanded before rendering.
 */
class PlatformAnnouncementMail extends BrandedMailable
{
    protected bool $transactional = false;

    public function __construct(
        public readonly string $headline,
        public readonly string $body,
        ?string $unsubscribeUrl = null,
    ) {
        $this->unsubscribeUrl = $unsubscribeUrl;
    }

    protected function subjectLine(): string
    {
        return $this->headline;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.announcement',
            with: [
                ...$this->layoutData(),
                'headline' => $this->headline,
                'body' => $this->body,
            ],
        );
    }
}
