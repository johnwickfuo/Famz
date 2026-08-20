<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A message from the contact form, to the company's own inbox.
 *
 * The only mailable on the platform whose reply-to is somebody other than the
 * company: an administrator reading this wants to hit reply and reach the
 * person who wrote it, not themselves.
 */
class ContactMessageMail extends BrandedMailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $fromEmail,
        // Not $subject: Illuminate\Mail\Mailable already declares one, and a
        // promoted readonly property of the same name is a fatal error rather
        // than an override.
        public readonly string $messageSubject,
        public readonly string $body,
    ) {}

    protected function subjectLine(): string
    {
        // Prefixed so it is obvious in an inbox that this came through the
        // website rather than from a customer's own mail client.
        return __('Website message: :subject', ['subject' => $this->messageSubject]);
    }

    public function envelope(): Envelope
    {
        return parent::envelope()->replyTo($this->fromEmail, $this->name);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.contact-message',
            with: [
                ...$this->layoutData(),
                'senderName' => $this->name,
                'senderEmail' => $this->fromEmail,
                'messageSubject' => $this->messageSubject,
                'messageBody' => $this->body,
            ],
        );
    }
}
