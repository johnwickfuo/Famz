<?php

namespace App\Mail;

use App\Models\SellerProfile;
use Illuminate\Mail\Mailables\Content;

class SellerRejectedMail extends BrandedMailable
{
    public function __construct(
        public readonly SellerProfile $seller,
        public readonly string $reason,
    ) {}

    protected function subjectLine(): string
    {
        return __('About your {company_short} seller application');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.seller-rejected',
            with: [
                ...$this->layoutData(),
                'seller' => $this->seller,
                'reason' => $this->reason,
            ],
        );
    }
}
