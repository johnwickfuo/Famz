<?php

namespace App\Mail;

use App\Models\Consultation;
use Illuminate\Mail\Mailables\Content;

class ConsultationBookedMail extends BrandedMailable
{
    public function __construct(public readonly Consultation $consultation) {}

    protected function subjectLine(): string
    {
        return __('We have your request — reference :reference', [
            'reference' => $this->consultation->reference,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.consultation-booked',
            with: [
                ...$this->layoutData(),
                'consultation' => $this->consultation,
            ],
        );
    }
}
