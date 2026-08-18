<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;

class WelcomeMail extends BrandedMailable
{
    public function __construct(public readonly User $user) {}

    protected function subjectLine(): string
    {
        return __('Welcome to {company}');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.welcome',
            with: [
                ...$this->layoutData(),
                'user' => $this->user,
            ],
        );
    }
}
