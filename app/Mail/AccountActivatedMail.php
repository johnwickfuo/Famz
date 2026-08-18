<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailables\Content;

class AccountActivatedMail extends BrandedMailable
{
    public function __construct(public readonly User $user) {}

    protected function subjectLine(): string
    {
        return __('Your {company_short} account is active');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.account-activated',
            with: [
                ...$this->layoutData(),
                'user' => $this->user,
            ],
        );
    }
}
