<?php

namespace App\Documents;

use App\Models\Quotation;
use App\Services\Branding\BrandingService;
use Illuminate\Support\Str;

/**
 * A farm setup proposal, as a document the client takes to a bank.
 *
 * Unlike course material, this one is theirs outright and downloading it is the
 * point: the whole reason somebody pays a study fee is to come away with a
 * costed document they can show a lender, a partner or a spouse. Locking it
 * down would defeat the product.
 *
 * The company's name comes off the QUOTATION rather than from BrandingService
 * once the proposal has been sent. Everything else on the letterhead — logo,
 * address, phone, email, RC number — is read live, because those are ways to
 * reach the company and a stale phone number helps nobody. The name is the
 * exception because it is what the document is signed with, and a proposal
 * reissued next year must still read as the one the client received.
 */
class QuotationProposalDocument extends BrandedDocument
{
    public function __construct(private readonly Quotation $quotation) {}

    protected function view(): string
    {
        return 'documents.quotation-proposal';
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        $branding = app(BrandingService::class);
        $quotation = $this->quotation->loadMissing('lineItems', 'request.user', 'preparer');

        return [
            'quotation' => $quotation,
            'request' => $quotation->request,
            'sections' => $quotation->sections(),
            'sectionTotals' => $quotation->sectionTotals(),
            'issuer' => [
                // Frozen at send time; falls back to live branding for a draft
                // being previewed before it has one.
                'name' => $quotation->issuer_name ?: $branding->name(),
                'short_name' => $branding->shortName(),
                'address' => $branding->address(),
                'phone' => $branding->phone(),
                'whatsapp' => $branding->whatsapp(),
                'email' => $branding->email(),
                'rc_number' => $branding->rcNumber(),
                'logo_url' => $branding->logoUrl(),
                'has_logo' => $branding->hasLogo(),
                'initials' => $branding->initials(),
            ],
        ];
    }

    public function filename(): string
    {
        $reference = $this->quotation->request?->reference ?? 'proposal';

        return Str::slug($reference.'-v'.$this->quotation->version).'-proposal.pdf';
    }
}
