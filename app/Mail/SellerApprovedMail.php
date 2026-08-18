<?php

namespace App\Mail;

use App\Models\SellerProfile;
use Illuminate\Mail\Mailables\Content;

class SellerApprovedMail extends BrandedMailable
{
    public function __construct(public readonly SellerProfile $seller) {}

    protected function subjectLine(): string
    {
        return __('You can start selling on {company}');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.seller-approved',
            with: [
                ...$this->layoutData(),
                'seller' => $this->seller,
            ],
        );
    }
}
