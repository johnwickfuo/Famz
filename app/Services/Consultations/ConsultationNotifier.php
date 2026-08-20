<?php

namespace App\Services\Consultations;

use App\Mail\ConsultationBookedMail;
use App\Mail\ConsultationQuotedMail;
use App\Mail\ConsultationReportReadyMail;
use App\Models\Consultation;
use App\Models\ConsultationReport;
use Illuminate\Support\Facades\Mail;

/**
 * Telling the client what has happened.
 *
 * Email rather than a database notification, and deliberately so: most of the
 * people booking these have no account. A guest gets a confirmation with their
 * reference in it, and that email IS their record — it is the only way back to
 * a booking they made from a phone at two in the morning.
 *
 * Every mailable here is a BrandedMailable, so it goes through the same layout,
 * sender name and placeholder expansion as everything else. All queued.
 */
class ConsultationNotifier
{
    public function booked(Consultation $consultation): void
    {
        Mail::to($consultation->email)->send(new ConsultationBookedMail($consultation));
    }

    public function quoted(Consultation $consultation): void
    {
        Mail::to($consultation->email)->send(new ConsultationQuotedMail($consultation));
    }

    public function reportPublished(Consultation $consultation, ConsultationReport $report): void
    {
        Mail::to($consultation->email)->send(new ConsultationReportReadyMail($consultation, $report));
    }
}
