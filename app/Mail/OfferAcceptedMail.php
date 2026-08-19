<?php

namespace App\Mail;

use App\Models\NegotiatedPurchase;
use App\Models\Offer;
use Illuminate\Mail\Mailables\Content;

class OfferAcceptedMail extends BrandedMailable
{
    public function __construct(
        public readonly Offer $offer,
        public readonly ?NegotiatedPurchase $purchase = null,
        public readonly bool $forBuyer = true,
    ) {}

    protected function subjectLine(): string
    {
        return $this->forBuyer
            ? __('Your offer was accepted')
            : __('You accepted an offer');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.offer-accepted',
            with: [
                ...$this->layoutData(),
                'offer' => $this->offer,
                'purchase' => $this->purchase,
                'forBuyer' => $this->forBuyer,
            ],
        );
    }
}
