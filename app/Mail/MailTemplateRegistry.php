<?php

namespace App\Mail;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationScope;
use App\Enums\QuotationStatus;
use App\Enums\UserStatus;
use App\Enums\WithdrawalStatus;
use App\Models\BuyerRequest;
use App\Models\Consultation;
use App\Models\ConsultationReport;
use App\Models\Course;
use App\Models\Dispute;
use App\Models\Enrolment;
use App\Models\MentorInvitation;
use App\Models\MentorshipEngagement;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutAccount;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\QuotationStudyFee;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\Withdrawal;
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
                'key' => 'seller-approved',
                'class' => SellerApprovedMail::class,
                'name' => __('Seller approved'),
                'description' => __('Sent when an application to sell is accepted.'),
                'transactional' => true,
            ],
            [
                'key' => 'seller-more-info',
                'class' => SellerMoreInfoMail::class,
                'name' => __('Seller: more information needed'),
                'description' => __('Sent when an application needs something before it can be decided.'),
                'transactional' => true,
            ],
            [
                'key' => 'seller-rejected',
                'class' => SellerRejectedMail::class,
                'name' => __('Seller rejected'),
                'description' => __('Sent when an application to sell is turned down.'),
                'transactional' => true,
            ],
            [
                'key' => 'offer-countered',
                'class' => OfferCounteredMail::class,
                'name' => __('Offer countered'),
                'description' => __('Sent when the other side answers an offer with a different price.'),
                'transactional' => true,
            ],
            [
                'key' => 'offer-rejected',
                'class' => OfferRejectedMail::class,
                'name' => __('Offer rejected'),
                'description' => __('Sent when an offer is declined outright.'),
                'transactional' => true,
            ],
            [
                'key' => 'buyer-request-expiring',
                'class' => BuyerRequestExpiringMail::class,
                'name' => __('Wanted ad closing soon'),
                'description' => __('Sent a few days before a request drops off the board.'),
                'transactional' => true,
            ],
            [
                'key' => 'dispute-raised',
                'class' => DisputeRaisedMail::class,
                'name' => __('Dispute raised'),
                'description' => __('Sent to the other party when a dispute is opened. Time-critical: the reference is in the subject.'),
                'transactional' => true,
            ],
            [
                'key' => 'withdrawal-paid',
                'class' => WithdrawalPaidMail::class,
                'name' => __('Payout paid'),
                'description' => __('Sent when money has actually left for a seller\'s bank. The amount is in the subject.'),
                'transactional' => true,
            ],
            [
                'key' => 'order-paid',
                'class' => OrderPaidMail::class,
                'name' => __('Order paid'),
                'description' => __("The buyer's receipt. The reference is in the subject, because that is what somebody searches their inbox for weeks later."),
                'transactional' => true,
            ],
            [
                'key' => 'seller-order-received',
                'class' => SellerOrderReceivedMail::class,
                'name' => __('New order for a seller'),
                'description' => __('Tells a seller they have something to pack. An order nobody knows about does not get packed.'),
                'transactional' => true,
            ],
            [
                'key' => 'order-shipped',
                'class' => OrderShippedMail::class,
                'name' => __('Order shipped'),
                'description' => __('Tells the buyer it has left, and that marking it delivered is what releases the money.'),
                'transactional' => true,
            ],
            [
                'key' => 'course-purchased',
                'class' => CoursePurchasedMail::class,
                'name' => __('Course purchased'),
                'description' => __('Carries the link to the first lesson. Course sales are final, so somebody who cannot find what they bought has no way out.'),
                'transactional' => true,
            ],
            [
                'key' => 'mentor-invitation',
                'class' => MentorInvitationMail::class,
                'name' => __('Mentor invitation'),
                'description' => __('The only route to becoming a mentor — there is no public signup.'),
                'transactional' => true,
            ],
            [
                'key' => 'engagement-confirmed',
                'class' => EngagementConfirmedMail::class,
                'name' => __('Engagement confirmed'),
                'description' => __('Sent to both sides when a mentorship goes live and the contact details unlock.'),
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
            SellerApprovedMail::class => new SellerApprovedMail($this->sampleSeller($user)),
            SellerMoreInfoMail::class => new SellerMoreInfoMail(
                $this->sampleSeller($user),
                __('Please send a clearer photograph of the CAC certificate — the one attached is cut off.'),
            ),
            SellerRejectedMail::class => new SellerRejectedMail(
                $this->sampleSeller($user),
                __('We could not confirm the business details given.'),
            ),
            OfferCounteredMail::class => new OfferCounteredMail($this->sampleOffer($user), '/offers'),
            OfferRejectedMail::class => new OfferRejectedMail(
                $this->sampleOffer($user),
                __('That is below what the feed costs us.'),
                '/offers',
            ),
            BuyerRequestExpiringMail::class => new BuyerRequestExpiringMail($this->sampleRequest($user), 3),
            DisputeRaisedMail::class => new DisputeRaisedMail($this->sampleDispute($user), '/disputes'),
            WithdrawalPaidMail::class => new WithdrawalPaidMail($this->sampleWithdrawal($user), '/seller/earnings'),
            OrderPaidMail::class => new OrderPaidMail($this->sampleOrder($user), '/orders'),
            SellerOrderReceivedMail::class => new SellerOrderReceivedMail(
                $this->sampleSubOrder($user),
                '/seller/sub-orders',
            ),
            OrderShippedMail::class => new OrderShippedMail($this->sampleSubOrder($user), '/orders'),
            CoursePurchasedMail::class => new CoursePurchasedMail($this->sampleEnrolment($user), '/academy'),
            MentorInvitationMail::class => new MentorInvitationMail(
                $this->sampleInvitation($user),
                url('/mentors/join/preview-token'),
            ),
            EngagementConfirmedMail::class => new EngagementConfirmedMail(
                $this->sampleEngagement($user),
                forMentor: false,
                actionUrl: '/mentorship',
            ),
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
     * An order, its one sub-order and its items — none of them saved.
     */
    private function sampleSubOrder(User $user): SubOrder
    {
        $item = new OrderItem;
        $item->forceFill([
            'product_name' => __('Layers mash, 25kg bag'),
            'quantity' => 20,
            'unit_price_kobo' => 1_650_000,
            'total_kobo' => 33_000_000,
        ]);

        $subOrder = new SubOrder;
        $subOrder->forceFill([
            'reference' => 'SO-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'subtotal_kobo' => 33_000_000,
            'delivery_fee_kobo' => 500_000,
            'total_kobo' => 33_500_000,
            'shipped_at' => now(),
        ]);

        $subOrder->setRelation('seller', $this->sampleSeller($user));
        $subOrder->setRelation('items', collect([$item]));
        $subOrder->setRelation('order', $this->sampleOrder($user, $subOrder));

        return $subOrder;
    }

    private function sampleOrder(User $user, ?SubOrder $subOrder = null): Order
    {
        $order = new Order;
        $order->forceFill([
            'reference' => 'OR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'subtotal_kobo' => 33_000_000,
            'delivery_fee_kobo' => 500_000,
            'total_kobo' => 33_500_000,
            'currency' => 'NGN',
            'delivery_state' => 'Oyo',
            'paid_at' => now(),
        ]);

        $order->setRelation('user', $user);

        /*
         * Only when the caller did not come from sampleSubOrder. Building a
         * sub-order here would recurse: the sub-order asks for its order, and
         * the order would ask for its sub-orders, forever.
         */
        $order->setRelation('subOrders', collect(array_filter([$subOrder])));

        return $order;
    }

    private function sampleEnrolment(User $user): Enrolment
    {
        $course = new Course;
        $course->forceFill([
            'title' => __('Brooder management for day-old chicks'),
            'slug' => 'brooder-management',
        ]);

        $enrolment = new Enrolment;
        $enrolment->forceFill(['price_paid_kobo' => 1_500_000]);

        $enrolment->setRelation('course', $course);
        $enrolment->setRelation('user', $user);

        return $enrolment;
    }

    private function sampleInvitation(User $user): MentorInvitation
    {
        $invitation = new MentorInvitation;
        $invitation->forceFill([
            'email' => $user->email,
            'name' => $user->displayName(),
            'note' => __('Twenty years in layers around Ibadan. Would be a good fit for people starting out.'),
            'expires_at' => now()->addDays(14),
        ]);

        return $invitation;
    }

    private function sampleEngagement(User $user): MentorshipEngagement
    {
        $engagement = new MentorshipEngagement;
        $engagement->forceFill([
            'reference' => 'MTR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'package_name' => __('Monthly check-in, three months'),
            'started_at' => now(),
        ]);

        $engagement->setRelation('client', $user);
        $engagement->setRelation('mentor', $user);

        return $engagement;
    }

    /**
     * A seller profile that exists only for the duration of a preview.
     */
    private function sampleSeller(User $user): SellerProfile
    {
        $seller = new SellerProfile;
        $seller->forceFill([
            // Deliberately not brand-shaped. Preview data lives in app/, which
            // the brand scan treats as source — and it is right to: a plausible
            // company name here is indistinguishable from a leaked one.
            'business_name' => __('A Sample Business'),
            'slug' => 'a-sample-business',
            'state' => 'Oyo',
        ]);

        $seller->setRelation('user', $user);

        return $seller;
    }

    /**
     * A dispute that exists only for the duration of a preview.
     */
    private function sampleDispute(User $user): Dispute
    {
        $subOrder = new SubOrder;
        $subOrder->forceFill([
            'reference' => 'SO-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'total_kobo' => 4_200_000,
        ]);

        $dispute = new Dispute;
        $dispute->forceFill([
            'reason' => DisputeReason::QualityPoor,
            'description' => __('Twelve of the forty bags were torn and the feed had caked.'),
            'status' => DisputeStatus::Open,
            'created_at' => now(),
        ]);

        $dispute->setRelation('subOrder', $subOrder);
        $dispute->setRelation('raisedBy', $user);

        return $dispute;
    }

    /**
     * A paid withdrawal that exists only for the duration of a preview.
     */
    private function sampleWithdrawal(User $user): Withdrawal
    {
        $account = new PayoutAccount;
        $account->forceFill([
            'account_name' => $user->displayName(),
            'account_number' => '0123456789',
            'bank_name' => __('Sample Bank'),
        ]);

        $withdrawal = new Withdrawal;
        $withdrawal->forceFill([
            'reference' => 'WD-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'amount_kobo' => 8_750_000,
            'currency' => 'NGN',
            'status' => WithdrawalStatus::Paid,
            'processed_at' => now(),
        ]);

        $withdrawal->setRelation('user', $user);
        $withdrawal->setRelation('payoutAccount', $account);

        return $withdrawal;
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
