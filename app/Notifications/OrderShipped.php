<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\OrderShippedMail;
use App\Models\SubOrder;

class OrderShipped extends PlatformNotification
{
    public function __construct(public readonly SubOrder $subOrder) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new OrderShippedMail($this->subOrder, url('/orders/parts/'.$this->subOrder->reference));
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
            'kind' => 'order.shipped',
            'sub_order_id' => $this->subOrder->getKey(),
            'title' => __(':reference is on its way', ['reference' => $this->subOrder->reference]),
            'body' => __('Mark it delivered when it arrives.'),
            'url' => '/orders/parts/'.$this->subOrder->reference,
        ];
    }
}
