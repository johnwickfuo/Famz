<?php

namespace App\Mail;

use App\Models\QuotationRequest;
use App\Support\Money;
use Illuminate\Mail\Mailables\Content;

class QuotationStudyFeeDueMail extends BrandedMailable
{
    public function __construct(public readonly QuotationRequest $request) {}

    protected function subjectLine(): string
    {
        return __('Your farm setup request — :reference', [
            'reference' => $this->request->reference,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.quotation-study-fee-due',
            with: [
                ...$this->layoutData(),
                'request' => $this->request,
                'fee' => $this->request->studyFee,
                'amount' => Money::fromKobo($this->request->studyFee?->amount_kobo ?? 0),
            ],
        );
    }
}
