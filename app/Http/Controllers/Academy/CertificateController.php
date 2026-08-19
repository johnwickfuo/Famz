<?php

namespace App\Http\Controllers\Academy;

use App\Documents\CompletionCertificate;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Services\Academy\CertificateIssuer;
use App\Services\Academy\EnrolmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateIssuer $issuer,
        private readonly EnrolmentService $enrolments,
    ) {}

    /**
     * Claim the certificate for a finished course.
     */
    public function issue(Request $request, Course $course): RedirectResponse
    {
        $enrolment = $this->enrolments->enrolmentFor($request->user(), $course);

        abort_if($enrolment === null || ! $enrolment->isActive(), 403);

        try {
            $certificate = $this->issuer->issue($enrolment);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('academy.certificate.show', $certificate->verification_code)
            ->with('success', __('Well done. Your certificate is ready.'));
    }

    /**
     * The holder's own copy.
     */
    public function show(Request $request, Certificate $certificate): Response
    {
        abort_unless($certificate->user_id === $request->user()->id, 403);

        return Inertia::render('Academy/Certificate', [
            'certificate' => $this->payload($certificate),
            'pdfUrl' => route('academy.certificate.pdf', $certificate->verification_code),
            'verifyUrl' => route('certificates.verify', $certificate->verification_code),
        ]);
    }

    /**
     * The PDF itself, rendered from what was frozen at issue.
     *
     * This one IS a download: a certificate is the student's to keep, print and
     * send to an employer. It is the opposite of course material, and treating
     * it the same way would make the thing they earned useless to them.
     */
    public function pdf(Request $request, Certificate $certificate): HttpResponse
    {
        abort_unless($certificate->user_id === $request->user()->id, 403);
        abort_unless($certificate->isValid(), 410);

        return CompletionCertificate::fromRecord($certificate)->download();
    }

    /**
     * The public check. No account needed — an employer holding a printed
     * certificate is exactly who this is for.
     */
    public function verify(string $code): Response
    {
        $certificate = Certificate::query()
            ->with('course')
            ->where('verification_code', strtoupper(trim($code)))
            ->first();

        return Inertia::render('Academy/Verify', [
            'code' => strtoupper(trim($code)),
            'certificate' => $certificate === null ? null : [
                ...$this->payload($certificate),
                // Deliberately not the holder's email or anything else about
                // them: this page proves a certificate is real, it is not a
                // lookup service for people's details.
                'is_valid' => $certificate->isValid(),
                'revoked_reason' => $certificate->revocation_reason,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Certificate $certificate): array
    {
        return [
            'code' => $certificate->verification_code,
            'holder' => $certificate->holder_name,
            'course' => $certificate->course_title,
            'issued_on' => $certificate->issued_at->format('j F Y'),
            'score' => $certificate->quiz_score_percent,
            // As it was the day it was issued, not as the company is called now.
            'issuer' => $certificate->issuer_name,
            'issuer_rc' => $certificate->issuer_rc_number,
        ];
    }
}
