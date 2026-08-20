<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\OfferReceivedMail;
use App\Models\Offer;

class OfferReceived extends PlatformNotification
{
    public function __construct(public readonly Offer $offer) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new OfferReceivedMail($this->offer, OfferLinks::for($this->offer, $notifiable));
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
            'kind' => 'offer.received',
            'offer_id' => $this->offer->getKey(),
            'title' => __('New offer from :name', [
                'name' => $this->offer->initiator?->displayName() ?? __('a buyer'),
            ]),
            'body' => __(':quantity at :price each — :total in total.', [
                'quantity' => $this->offer->quantity,
                'price' => $this->offer->unitPrice(),
                'total' => $this->offer->totalPrice(),
            ]),
            'url' => OfferLinks::for($this->offer, $notifiable),
        ];
    }
}
