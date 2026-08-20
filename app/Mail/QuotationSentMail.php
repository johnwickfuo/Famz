<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Mail\Mailables\Content;

class QuotationSentMail extends BrandedMailable
{
    public function __construct(public readonly Quotation $quotation) {}

    protected function subjectLine(): string
    {
        return __('Your proposal — :reference', [
            'reference' => $this->quotation->request?->reference ?? '',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.quotation-sent',
            with: [
                ...$this->layoutData(),
                'quotation' => $this->quotation,
                'request' => $this->quotation->request,
            ],
        );
    }
}
