<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\OrderPaidMail;
use App\Models\Order;

class OrderPaid extends PlatformNotification
{
    public function __construct(public readonly Order $order) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new OrderPaidMail($this->order, url('/orders/'.$this->order->reference));
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
            'kind' => 'order.paid',
            'order_id' => $this->order->getKey(),
            'title' => __('Payment received for :reference', ['reference' => $this->order->reference]),
            'body' => __('Your money is held until what you ordered arrives.'),
            'url' => '/orders/'.$this->order->reference,
        ];
    }
}
