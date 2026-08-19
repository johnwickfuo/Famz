<?php

namespace App\Mail;

use App\Models\BuyerRequest;
use Illuminate\Mail\Mailables\Content;

class BuyerRequestReviewedMail extends BrandedMailable
{
    public function __construct(
        public readonly BuyerRequest $buyerRequest,
        public readonly bool $approved,
    ) {}

    protected function subjectLine(): string
    {
        return $this->approved
            ? __('Your request is live on {company}')
            : __('We could not publish your request');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.buyer-request-reviewed',
            with: [
                ...$this->layoutData(),
                'buyerRequest' => $this->buyerRequest,
                'approved' => $this->approved,
            ],
        );
    }
}
