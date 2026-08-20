<?php

namespace App\Mail;

use App\Models\Consultation;
use Illuminate\Mail\Mailables\Content;

class ConsultationQuotedMail extends BrandedMailable
{
    public function __construct(public readonly Consultation $consultation) {}

    protected function subjectLine(): string
    {
        return __('Your consultation price — :amount', [
            'amount' => $this->consultation->quotedAmount() ?? '',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.consultation-quoted',
            with: [
                ...$this->layoutData(),
                'consultation' => $this->consultation,
            ],
        );
    }
}
