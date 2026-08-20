<?php

namespace App\Mail;

use App\Models\Consultation;
use App\Models\ConsultationReport;
use Illuminate\Mail\Mailables\Content;

class ConsultationReportReadyMail extends BrandedMailable
{
    public function __construct(
        public readonly Consultation $consultation,
        public readonly ConsultationReport $report,
    ) {}

    protected function subjectLine(): string
    {
        return __('Your consultation report is ready');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.consultation-report',
            with: [
                ...$this->layoutData(),
                'consultation' => $this->consultation,
                'report' => $this->report,
            ],
        );
    }
}
