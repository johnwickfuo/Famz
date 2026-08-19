<?php

namespace App\Notifications;

use App\Mail\BrandedMailable;
use App\Mail\OfferAcceptedMail;
use App\Models\NegotiatedPurchase;
use App\Models\Offer;

class OfferAccepted extends PlatformNotification
{
    public function __construct(
        public readonly Offer $offer,
        public readonly ?NegotiatedPurchase $purchase = null,
        public readonly bool $forBuyer = true,
    ) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new OfferAcceptedMail($this->offer, $this->purchase, $this->forBuyer);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'offer.accepted',
            'offer_id' => $this->offer->getKey(),
            'title' => $this->forBuyer
                ? __('Your offer was accepted')
                : __('You accepted an offer'),
            'body' => $this->forBuyer && $this->purchase !== null
                ? __('Pay :total by :when to complete it.', [
                    'total' => $this->offer->totalPrice(),
                    'when' => $this->purchase->expires_at->format('j M, H:i'),
                ])
                : __(':quantity at :price each — :total in total.', [
                    'quantity' => $this->offer->quantity,
                    'price' => $this->offer->unitPrice(),
                    'total' => $this->offer->totalPrice(),
                ]),
            'url' => $this->forBuyer && $this->purchase !== null
                ? route('negotiated.show', $this->purchase->token)
                : OfferLinks::for($this->offer, $notifiable),
        ];
    }
}
