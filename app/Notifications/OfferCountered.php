<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\OfferCounteredMail;
use App\Models\Offer;

class OfferCountered extends PlatformNotification
{
    public function __construct(public readonly Offer $offer) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new OfferCounteredMail($this->offer, OfferLinks::for($this->offer, $notifiable));
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Offers;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'offer.countered',
            'offer_id' => $this->offer->getKey(),
            'title' => __('Countered at :price', ['price' => $this->offer->unitPrice()]),
            'body' => __(':name has come back with :quantity at :price each.', [
                'name' => $this->offer->initiator?->displayName() ?? __('The other side'),
                'quantity' => $this->offer->quantity,
                'price' => $this->offer->unitPrice(),
            ]),
            'url' => OfferLinks::for($this->offer, $notifiable),
        ];
    }
}
