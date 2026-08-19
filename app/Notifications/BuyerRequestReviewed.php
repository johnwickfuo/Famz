<?php

namespace App\Notifications;

use App\Mail\BrandedMailable;
use App\Mail\BuyerRequestReviewedMail;
use App\Models\BuyerRequest;

class BuyerRequestReviewed extends PlatformNotification
{
    public function __construct(
        public readonly BuyerRequest $buyerRequest,
        public readonly bool $approved,
    ) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new BuyerRequestReviewedMail($this->buyerRequest, $this->approved);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->approved ? 'request.approved' : 'request.rejected',
            'buyer_request_id' => $this->buyerRequest->getKey(),
            'title' => $this->approved
                ? __('Your request is live')
                : __('We could not publish your request'),
            'body' => $this->approved
                ? __('Sellers can see it now and will start sending offers.')
                : ($this->buyerRequest->rejection_reason ?? __('Have a look at the reason and try again.')),
            'url' => $this->approved
                ? route('requests.show', $this->buyerRequest->slug)
                : route('requests.mine'),
        ];
    }
}
