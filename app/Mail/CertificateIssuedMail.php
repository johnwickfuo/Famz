<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;

class CertificateIssuedMail extends BrandedMailable
{
    public function __construct(
        public readonly User $user,
        public readonly string $courseTitle,
        public readonly string $reference,
    ) {}

    protected function subjectLine(): string
    {
        return __('Your certificate from {company}');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.certificate-issued',
            with: [
                ...$this->layoutData(),
                'user' => $this->user,
                'courseTitle' => $this->courseTitle,
                'reference' => $this->reference,
            ],
        );
    }
}
