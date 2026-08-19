<?php

namespace App\Mail;

use App\Models\BuyerRequest;
use Illuminate\Mail\Mailables\Content;

class BuyerRequestExpiringMail extends BrandedMailable
{
    public function __construct(
        public readonly BuyerRequest $buyerRequest,
        public readonly int $daysLeft,
    ) {}

    protected function subjectLine(): string
    {
        return __('Your request closes soon');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.buyer-request-expiring',
            with: [
                ...$this->layoutData(),
                'buyerRequest' => $this->buyerRequest,
                'daysLeft' => $this->daysLeft,
            ],
        );
    }
}
