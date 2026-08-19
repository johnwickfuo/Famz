<?php

namespace App\Mail;

use App\Models\Offer;
use Illuminate\Mail\Mailables\Content;

class OfferRejectedMail extends BrandedMailable
{
    public function __construct(
        public readonly Offer $offer,
        public readonly ?string $reason = null,
        public readonly string $actionUrl = '/',
    ) {}

    protected function subjectLine(): string
    {
        return __('Your offer was not taken up');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.offer-rejected',
            with: [...$this->layoutData(), 'offer' => $this->offer, 'reason' => $this->reason, 'actionUrl' => $this->actionUrl],
        );
    }
}
