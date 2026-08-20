<?php

namespace App\Documents;

use App\Models\ConsultationReport;
use App\Services\Branding\BrandingService;
use Illuminate\Support\Str;

/**
 * A consultation report as a document the client can keep.
 *
 * Unlike course material, this one is theirs: they paid for the advice and a
 * report they cannot save, print or show to a vet is worth much less than one
 * they can.
 *
 * The company's identity comes from BrandingService at render time rather than
 * being frozen the way a certificate's is. A certificate is a claim about a
 * particular day and has to keep saying what it said; a report is a document
 * the client asked for now, and it should carry the company as it is now.
 */
class ConsultationReportDocument extends BrandedDocument
{
    public function __construct(private readonly ConsultationReport $report) {}

    protected function view(): string
    {
        return 'documents.consultation-report';
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        $branding = app(BrandingService::class);
        $consultation = $this->report->consultation;

        return [
            'report' => $this->report,
            'consultation' => $consultation,
            'issuer' => [
                'name' => $branding->name(),
                'short_name' => $branding->shortName(),
                'rc_number' => $branding->rcNumber(),
                'email' => $branding->email(),
                'phone' => $branding->phone(),
                'logo_url' => $branding->logoUrl(),
            ],
        ];
    }

    public function filename(): string
    {
        return Str::slug($this->report->consultation?->reference ?? 'consultation').'-report.pdf';
    }
}
