<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Mail\Mailables\Content;

class OfferCounteredMail extends BrandedMailable
{
    public function __construct(
        public readonly Offer $offer,
        public readonly string $actionUrl = '/',
    ) {}

    protected function subjectLine(): string
    {
        return __('Your offer has been countered');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.offer-countered',
            with: [...$this->layoutData(), 'offer' => $this->offer, 'actionUrl' => $this->actionUrl],
        );
    }
}
