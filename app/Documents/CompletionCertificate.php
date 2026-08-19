<?php

namespace App\Documents;

use App\Models\Certificate;
use App\Models\User;
use App\Services\Branding\BrandingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A training certificate.
 *
 * It carries the company's name, logo, registration number and signature block
 * without ever naming any of them — but from two different places depending on
 * why it is being rendered:
 *
 *  · From an issued Certificate record, whose issuer fields were COPIED at
 *    issue time. A student downloading theirs again in two years gets the
 *    document they were given, not one bearing whatever the company has since
 *    renamed itself.
 *
 *  · From BrandingService live, for a preview an administrator asks for while
 *    setting their branding up. Nothing has been issued, so there is nothing to
 *    be faithful to yet.
 */
class CompletionCertificate extends BrandedDocument
{
    protected string $orientation = 'landscape';

    /**
     * @param  array<string, string|null>  $issuer
     */
    private function __construct(
        private readonly string $holderName,
        private readonly string $courseTitle,
        private readonly string $reference,
        private readonly Carbon $issuedAt,
        private readonly array $issuer,
        private readonly ?int $scorePercent = null,
    ) {}

    /**
     * The real thing, rendered from what was frozen at issue.
     */
    public static function fromRecord(Certificate $certificate): self
    {
        return new self(
            holderName: $certificate->holder_name,
            courseTitle: $certificate->course_title,
            reference: $certificate->verification_code,
            issuedAt: $certificate->issued_at,
            issuer: [
                'name' => $certificate->issuer_name,
                'short_name' => $certificate->issuer_short_name ?: $certificate->issuer_name,
                'rc_number' => $certificate->issuer_rc_number,
                'logo_url' => self::logoUrl($certificate->issuer_logo_path),
                'signatory_name' => $certificate->issuer_signature_name,
                'signatory_title' => $certificate->issuer_signature_title,
            ],
            scorePercent: $certificate->quiz_score_percent,
        );
    }

    /**
     * A sample, using the company as it stands right now.
     */
    public static function for(User $holder, string $courseTitle, ?string $reference = null): self
    {
        $branding = app(BrandingService::class);

        return new self(
            holderName: $holder->displayName(),
            courseTitle: $courseTitle,
            reference: $reference ?? 'CERT-'.Str::upper(Str::random(8)),
            issuedAt: now(),
            issuer: [
                'name' => $branding->name(),
                'short_name' => $branding->shortName(),
                'rc_number' => $branding->rcNumber(),
                'logo_url' => $branding->logoUrl(),
                'signatory_name' => $branding->signatoryName(),
                'signatory_title' => $branding->signatoryTitle(),
            ],
        );
    }

    protected function view(): string
    {
        return 'certificates.completion';
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return [
            'holderName' => $this->holderName,
            'courseTitle' => $this->courseTitle,
            'reference' => $this->reference,
            'issuedAt' => $this->issuedAt,
            'issuer' => $this->issuer,
            'scorePercent' => $this->scorePercent,
            'verifyUrl' => route('certificates.verify', $this->reference),
        ];
    }

    public function filename(): string
    {
        return Str::slug($this->courseTitle.' certificate '.$this->reference).'.pdf';
    }

    /**
     * A path on the public disk turned into something DomPDF can fetch.
     *
     * Stored as a path rather than a URL on purpose: a URL captured two years
     * ago would break the day the application moves domain, and the certificate
     * has to keep rendering for as long as the student keeps it.
     */
    private static function logoUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk((string) config('branding.disk', 'public'))->url($path);
    }
}
