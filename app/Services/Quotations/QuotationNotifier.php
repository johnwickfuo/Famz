<?php

namespace App\Services\Quotations;

use App\Mail\QuotationExpiredMail;
use App\Mail\QuotationSentMail;
use App\Mail\QuotationStudyFeeDueMail;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Services\Branding\BrandingService;
use Illuminate\Support\Facades\Mail;

/**
 * Telling people what has happened to a proposal.
 *
 * Every mailable here is a BrandedMailable, so it goes through the same layout,
 * sender name and placeholder expansion as everything else, and all of them are
 * queued.
 *
 * Expiry is the one event that goes to both sides. The client needs to know the
 * price they are holding has lapsed before they ring up quoting it; the company
 * needs to know because a lapsed proposal is a warm lead going cold, and that is
 * worth somebody picking up the phone about.
 */
class QuotationNotifier
{
    public function __construct(private readonly BrandingService $branding) {}

    public function studyFeeDue(QuotationRequest $request): void
    {
        $email = $request->user?->email;

        if ($email === null) {
            return;
        }

        Mail::to($email)->send(new QuotationStudyFeeDueMail($request));
    }

    public function quotationSent(Quotation $quotation): void
    {
        $email = $quotation->request?->user?->email;

        if ($email === null) {
            return;
        }

        Mail::to($email)->send(new QuotationSentMail($quotation));
    }

    /**
     * Both sides, in that order.
     */
    public function quotationExpired(Quotation $quotation): void
    {
        $mailable = new QuotationExpiredMail($quotation);

        $clientEmail = $quotation->request?->user?->email;

        if ($clientEmail !== null) {
            Mail::to($clientEmail)->send($mailable);
        }

        $companyEmail = $this->companyAddress();

        // Guard against the company's own address being the client's, which
        // happens on a demo install and would send the same person two copies.
        if ($companyEmail !== null && $companyEmail !== $clientEmail) {
            Mail::to($companyEmail)->send(new QuotationExpiredMail($quotation));
        }
    }

    /**
     * Where to reach the company.
     *
     * From BrandingService, never a literal and never a config value — the
     * administrator sets this on the settings screen along with everything else
     * about the company's identity.
     */
    private function companyAddress(): ?string
    {
        $email = $this->branding->email();

        return filled($email) ? $email : null;
    }
}
