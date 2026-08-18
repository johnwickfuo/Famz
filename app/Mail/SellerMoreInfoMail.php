<?php

namespace App\Mail;

use App\Models\SellerProfile;
use Illuminate\Mail\Mailables\Content;

class SellerMoreInfoMail extends BrandedMailable
{
    public function __construct(
        public readonly SellerProfile $seller,
        public readonly string $notes,
    ) {}

    protected function subjectLine(): string
    {
        return __('We need a little more for your {company_short} application');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.seller-more-info',
            with: [
                ...$this->layoutData(),
                'seller' => $this->seller,
                'notes' => $this->notes,
            ],
        );
    }
}
