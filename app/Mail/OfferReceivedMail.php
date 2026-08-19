<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Mail\Mailables\Content;

class OfferReceivedMail extends BrandedMailable
{
    public function __construct(
        public readonly Offer $offer,
        public readonly string $actionUrl = '/',
    ) {}

    protected function subjectLine(): string
    {
        return __('You have an offer on {company}');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.offer-received',
            with: [...$this->layoutData(), 'offer' => $this->offer, 'actionUrl' => $this->actionUrl],
        );
    }
}
