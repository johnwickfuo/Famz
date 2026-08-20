<?php

namespace App\Mail;

use App\Models\Quotation;
use Illuminate\Mail\Mailables\Content;

class QuotationExpiredMail extends BrandedMailable
{
    public function __construct(public readonly Quotation $quotation) {}

    protected function subjectLine(): string
    {
        return __('Your proposal has lapsed — :reference', [
            'reference' => $this->quotation->request?->reference ?? '',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.quotation-expired',
            with: [
                ...$this->layoutData(),
                'quotation' => $this->quotation,
                'request' => $this->quotation->request,
            ],
        );
    }
}
