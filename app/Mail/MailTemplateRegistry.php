<?php

namespace App\Mail;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationScope;
use App\Enums\QuotationStatus;
use App\Enums\UserStatus;
use App\Models\BuyerRequest;
use App\Models\Consultation;
use App\Models\ConsultationReport;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\QuotationStudyFee;
use App\Models\User;
use App\Services\Quotations\StudyFeeService;
use Illuminate\Support\Str;

/**
 * Every branded mail class the platform can send, with a way to build a
 * realistic sample of each. The admin mail templates page lists these and
 * sends test copies so branding can be checked against the real thing.
 */
class MailTemplateRegistry
{
    /**
     * @return array<int, array{key: string, class: class-string<BrandedMailable>, name: string, description: string, transactional: bool}>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'welcome',
                'class' => WelcomeMail::class,
                'name' => __('Welcome'),
                'description' => __('Sent when somebody finishes registering.'),
                'transactional' => true,
            ],
            [
                'key' => 'account-activated',
                'class' => AccountActivatedMail::class,
                'name' => __('Account activated'),
                'description' => __('Sent when an administrator moves an account from pending to active.'),
                'transactional' => true,
            ],
            [
                'key' => 'certificate-issued',
                'class' => CertificateIssuedMail::class,
                'name' => __('Certificate issued'),
                'description' => __('Sent when a training certificate is issued.'),
                'transactional' => true,
            ],
            [
                'key' => 'offer-received',
                'class' => OfferReceivedMail::class,
                'name' => __('Offer received'),
                'description' => __('Sent when somebody makes an offer on a listing or answers a wanted ad.'),
                'transactional' => true,
            ],
            [
                'key' => 'offer-accepted',
                'class' => OfferAcceptedMail::class,
                'name' => __('Offer accepted'),
                'description' => __('Sent to both sides. The buyer\'s copy carries the private checkout link.'),
                'transactional' => true,
            ],
            [
                'key' => 'buyer-request-reviewed',
                'class' => BuyerRequestReviewedMail::class,
                'name' => __('Buyer request reviewed'),
                'description' => __('Sent when an administrator publishes a wanted ad, or declines to.'),
                'transactional' => true,
            ],
            [
                'key' => 'announcement',
                'class' => PlatformAnnouncementMail::class,
                'name' => __('Platform announcement'),
                'description' => __('Admin-authored. Carries an unsubscribe link; {company} placeholders are expanded.'),
                'transactional' => false,
            ],
            [
                'key' => 'consultation-booked',
                'class' => ConsultationBookedMail::class,
                'name' => __('Consultation booked'),
                'description' => __('Sent the moment somebody books a consultation, with their reference and the response promise.'),
                'transactional' => true,
            ],
            [
                'key' => 'consultation-quoted',
                'class' => ConsultationQuotedMail::class,
                'name' => __('Consultation quoted'),
                'description' => __('Sent when an administrator sets a price, carrying the link to pay.'),
                'transactional' => true,
            ],
            [
                'key' => 'consultation-report',
                'class' => ConsultationReportReadyMail::class,
                'name' => __('Consultation report ready'),
                'description' => __('Sent when a report is published to the client.'),
                'transactional' => true,
            ],
            [
                'key' => 'quotation-study-fee-due',
                'class' => QuotationStudyFeeDueMail::class,
                'name' => __('Study fee due'),
                'description' => __('Sent when somebody asks for a farm setup quotation, carrying the link to pay the study fee.'),
                'transactional' => true,
            ],
            [
                'key' => 'quotation-sent',
                'class' => QuotationSentMail::class,
                'name' => __('Proposal sent'),
                'description' => __('Sent when a farm setup proposal is delivered to the client.'),
                'transactional' => true,
            ],
            [
                'key' => 'quotation-expired',
                'class' => QuotationExpiredMail::class,
                'name' => __('Proposal lapsed'),
                'description' => __('Sent to the client and to the company when a proposal passes its validity date.'),
                'transactional' => true,
            ],
        ];
    }

    /**
     * @return array{key: string, class: class-string<BrandedMailable>, name: string, description: string, transactional: bool}|null
     */
    public function find(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }

    /**
     * Build a sample of one template, using the given user where the template
     * needs one. Nothing is persisted.
     */
    public function sample(string $key, ?User $user = null): ?BrandedMailable
    {
        $template = $this->find($key);

        if ($template === null) {
            return null;
        }

        $user ??= $this->placeholderUser();

        return match ($template['class']) {
            OfferReceivedMail::class => new OfferReceivedMail($this->sampleOffer($user), '/seller/offers'),
            OfferAcceptedMail::class => new OfferAcceptedMail($this->sampleOffer($user), null, forBuyer: true),
            BuyerRequestReviewedMail::class => new BuyerRequestReviewedMail(
                $this->sampleRequest($user),
                approved: true,
            ),
            WelcomeMail::class => new WelcomeMail($user),
            AccountActivatedMail::class => new AccountActivatedMail($user),
            CertificateIssuedMail::class => new CertificateIssuedMail(
                $user,
                __('Brooder management for day-old chicks'),
                'CERT-'.Str::upper(Str::random(8)),
            ),
            ConsultationBookedMail::class => new ConsultationBookedMail($this->sampleConsultation($user)),
            ConsultationQuotedMail::class => new ConsultationQuotedMail(
                $this->sampleConsultation($user, quoted: true),
            ),
            ConsultationReportReadyMail::class => (function () use ($user): ConsultationReportReadyMail {
                $consultation = $this->sampleConsultation($user, quoted: true);

                $report = new ConsultationReport;
                $report->forceFill([
                    'consultation_id' => $consultation->id,
                    'title' => __('Brooder losses: findings and what to change'),
                    'findings' => __('Placeholder findings for the preview.'),
                    'recommendations' => __('Placeholder recommendations for the preview.'),
                ]);

                return new ConsultationReportReadyMail($consultation, $report);
            })(),
            QuotationStudyFeeDueMail::class => new QuotationStudyFeeDueMail($this->sampleQuotationRequest($user)),
            QuotationSentMail::class => new QuotationSentMail($this->sampleQuotation($user)),
            QuotationExpiredMail::class => new QuotationExpiredMail($this->sampleQuotation($user, lapsed: true)),
            PlatformAnnouncementMail::class => new PlatformAnnouncementMail(
                __('A note from {company}'),
                __("This is a preview of how an announcement from {company} looks.\n\nAnything an administrator writes here is sent with the platform's own branding, and {company_short} is filled in from the settings screen."),
                url('/'),
            ),
            default => null,
        };
    }

    /**
     * A consultation that exists only for the duration of a preview.
     *
     * Never saved: `forceFill` on an unsaved model, exactly like the sample
     * offer above, so previewing a template does not put a fake booking into
     * somebody's queue.
     */
    private function sampleQuotationRequest(User $user): QuotationRequest
    {
        $request = new QuotationRequest;

        $request->forceFill([
            'id' => 0,
            'reference' => 'QR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'user_id' => $user->getKey(),
            'project_type' => QuotationProjectType::NewBuild,
            'farm_type' => __('Layer poultry'),
            'target_capacity' => 20000,
            'capacity_unit' => __('birds'),
            'owns_land' => true,
            'land_size' => 3,
            'land_unit' => __('hectares'),
            'state' => 'Oyo',
            'lga' => 'Akinyele',
            'scope_wanted' => [
                QuotationScope::Construction->value,
                QuotationScope::EquipmentSupply->value,
                QuotationScope::Stocking->value,
            ],
            'status' => QuotationRequestStatus::StudyFeePending,
            'created_at' => now(),
        ]);

        $fee = new QuotationStudyFee;
        $fee->forceFill([
            'id' => 0,
            'quotation_request_id' => 0,
            'amount_kobo' => app(StudyFeeService::class)->currentAmountKobo(),
        ]);

        // Set on the relation rather than saved, so the preview renders without
        // writing anything.
        $request->setRelation('studyFee', $fee);
        $request->setRelation('user', $user);

        return $request;
    }

    private function sampleQuotation(User $user, bool $lapsed = false): Quotation
    {
        $request = $this->sampleQuotationRequest($user);

        $quotation = new Quotation;

        $quotation->forceFill([
            'id' => 0,
            'quotation_request_id' => 0,
            'version' => $lapsed ? 1 : 2,
            'title' => __('20,000-bird layer farm — Akinyele, Oyo'),
            // Kobo. A 20,000-bird layer build lands around here in practice,
            // and a preview with a plausible number is worth more than one
            // with a round one.
            'subtotal_kobo' => 4_850_000_000,
            'contingency_percent' => 10,
            'contingency_kobo' => 485_000_000,
            'total_kobo' => 5_335_000_000,
            'status' => $lapsed ? QuotationStatus::Expired : QuotationStatus::Sent,
            'sent_at' => $lapsed ? now()->subDays(45) : now(),
            'valid_until' => $lapsed ? now()->subDays(15) : now()->addDays(30),
        ]);

        $quotation->setRelation('request', $request);

        return $quotation;
    }

    private function sampleConsultation(User $user, bool $quoted = false): Consultation
    {
        $consultation = new Consultation;

        $consultation->forceFill([
            'id' => 0,
            'reference' => 'CON-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'full_name' => $user->displayName(),
            'phone' => $user->profile?->phone ?? '0803 000 0000',
            'email' => $user->email,
            'tier' => ConsultationTier::Urgent,
            'status' => $quoted ? ConsultationStatus::Quoted : ConsultationStatus::Submitted,
            'situation' => __('Losing about ten birds a day in a 2,000 layer house.'),
            'response_due_at' => now()->addHours(6),
            'quoted_amount_kobo' => $quoted ? 3_500_000 : null,
            'quoted_at' => $quoted ? now() : null,
            'quote_note' => $quoted ? __('Includes a farm visit and a written report.') : null,
            'created_at' => now(),
        ]);

        return $consultation;
    }

    /**
     * A user that exists only for the duration of a preview.
     */
    /**
     * A believable offer, built in memory and never saved.
     *
     * The templates read the offer and whatever it is about, so the preview
     * has to carry both — a sample that renders a blank line teaches an
     * administrator nothing about their branding.
     */
    private function sampleOffer(User $user): Offer
    {
        $product = new Product([
            'name' => __('Layers mash, 25kg bag'),
            'slug' => 'layers-mash-25kg',
        ]);
        $product->id = 0;
        $product->exists = false;

        $offer = new Offer;
        $offer->forceFill([
            'quantity' => 20,
            'unit_price_kobo' => 1_650_000,
            'total_price_kobo' => 33_000_000,
            'message' => __('Can you do better for twenty bags? I collect myself.'),
            'expires_at' => now()->addDays(3),
        ]);

        $offer->setRelation('offerable', $product);
        $offer->setRelation('initiator', $user);

        return $offer;
    }

    private function sampleRequest(User $user): BuyerRequest
    {
        $request = new BuyerRequest([
            'title' => __('Wanted: 300 point-of-lay pullets'),
            'quantity' => 300,
            'unit' => 'bird',
            'delivery_state' => 'Oyo',
            'delivery_lga' => 'Akinyele',
        ]);

        $request->forceFill([
            'slug' => 'wanted-300-point-of-lay-pullets',
            'reference' => 'REQ-000000-SAMPLE',
            'budget_min_kobo' => 250_000,
            'budget_max_kobo' => 320_000,
            'expires_at' => now()->addDays(14),
        ]);

        $request->id = 0;
        $request->exists = false;
        $request->setRelation('buyer', $user);

        return $request;
    }

    private function placeholderUser(): User
    {
        $user = new User([
            'name' => __('Test recipient'),
            'email' => 'preview@example.test',
            'status' => UserStatus::Active,
        ]);

        $user->id = 0;
        $user->exists = false;

        return $user;
    }
}
