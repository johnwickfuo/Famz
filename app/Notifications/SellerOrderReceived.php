<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\SellerOrderReceivedMail;
use App\Models\SubOrder;

class SellerOrderReceived extends PlatformNotification
{
    public function __construct(public readonly SubOrder $subOrder) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new SellerOrderReceivedMail($this->subOrder, url('/seller/sub-orders'));
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Orders;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'order.received',
            'sub_order_id' => $this->subOrder->getKey(),
            'title' => __('New order :reference', ['reference' => $this->subOrder->reference]),
            'body' => __('Paid for and waiting on you.'),
            'url' => '/seller/sub-orders',
        ];
    }
}
