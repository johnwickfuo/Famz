<?php

namespace App\Mail;

use App\Enums\UserStatus;
use App\Models\BuyerRequest;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
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
            PlatformAnnouncementMail::class => new PlatformAnnouncementMail(
                __('A note from {company}'),
                __("This is a preview of how an announcement from {company} looks.\n\nAnything an administrator writes here is sent with the platform's own branding, and {company_short} is filled in from the settings screen."),
                url('/'),
            ),
            default => null,
        };
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
