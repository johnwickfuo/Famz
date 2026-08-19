<?php

namespace App\Notifications;

use App\Mail\BrandedMailable;
use App\Mail\BuyerRequestExpiringMail;
use App\Models\BuyerRequest;

class BuyerRequestExpiring extends PlatformNotification
{
    public function __construct(
        public readonly BuyerRequest $buyerRequest,
        public readonly int $daysLeft,
    ) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new BuyerRequestExpiringMail($this->buyerRequest, $this->daysLeft);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'request.expiring',
            'buyer_request_id' => $this->buyerRequest->getKey(),
            'title' => trans_choice(
                'Your request closes tomorrow|Your request closes in :count days',
                $this->daysLeft,
                ['count' => $this->daysLeft],
            ),
            'body' => __('":title" — :count offer(s) so far.', [
                'title' => $this->buyerRequest->title,
                'count' => $this->buyerRequest->offers()->count(),
            ]),
            'url' => route('requests.show', $this->buyerRequest->slug),
        ];
    }
}
