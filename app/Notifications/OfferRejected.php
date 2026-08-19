<?php

namespace App\Notifications;

use App\Mail\BrandedMailable;
use App\Mail\OfferRejectedMail;
use App\Models\Offer;

class OfferRejected extends PlatformNotification
{
    public function __construct(
        public readonly Offer $offer,
        public readonly ?string $reason = null,
    ) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new OfferRejectedMail($this->offer, $this->reason, OfferLinks::for($this->offer, $notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'offer.rejected',
            'offer_id' => $this->offer->getKey(),
            'title' => __('Your offer was not taken up'),
            'body' => $this->reason ?? __('Nothing was agreed this time.'),
            'url' => OfferLinks::for($this->offer, $notifiable),
        ];
    }
}
