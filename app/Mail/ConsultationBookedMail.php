<?php

namespace App\Mail;

use App\Models\Consultation;
use Illuminate\Mail\Mailables\Content;

class ConsultationBookedMail extends BrandedMailable
{
    public function __construct(public readonly Consultation $consultation) {}

    protected function subjectLine(): string
    {
        /*
         * An urgent consultation gets a subject that says so, with the
         * deadline in it.
         *
         * Somebody paying extra for a same-day answer about dying birds is
         * watching their inbox. "We have your request" reads as a receipt and
         * gets left for later; naming the tier and the hours tells them the
         * clock they paid for has started, and tells them when to chase us if
         * it has not.
         */
        if ($this->consultation->tier->isUrgent()) {
            return __('URGENT consultation :reference — we reply within :hours working hours', [
                'reference' => $this->consultation->reference,
                'hours' => $this->consultation->tier->hours(),
            ]);
        }

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
