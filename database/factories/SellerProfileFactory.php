<?php

namespace Database\Factories;

use App\Enums\BusinessType;
use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use App\Models\User;
use App\Support\Nigeria;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SellerProfile>
 */
class SellerProfileFactory extends Factory
{
    /**
     * Trading names that read like real Nigerian agricultural businesses
     * rather than Faker's Latin. Deliberately generic — none of these is the
     * client, whose name has still not been chosen.
     *
     * @var array<int, string>
     */
    private const PREFIXES = [
        'Alhaji Musa', 'Mama Ngozi', 'Oga Tunde', 'Sabon Gari', 'Ilorin',
        'Zaria', 'Ogbomoso', 'Nnewi', 'Bodija', 'Dawanau', 'Mile 12',
        'Kaduna Central', 'Owerri', 'Jos Plateau', 'Ondo Valley',
    ];

    /**
     * @var array<int, string>
     */
    private const SUFFIXES = [
        'Agro Ventures', 'Farms', 'Feeds & Chicks', 'Poultry Supplies',
        'Agro Allied', 'Livestock Stores', 'Farm Services', 'Agro Depot',
        'Nigeria Enterprises', 'Cooperative Society',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $businessName = fake()->randomElement(self::PREFIXES).' '.fake()->randomElement(self::SUFFIXES);
        $type = fake()->randomElement(BusinessType::cases());

        return [
            'user_id' => User::factory(),
            'business_name' => $businessName,
            'slug' => Str::slug($businessName).'-'.Str::lower(Str::random(5)),

            // Most market traders are not CAC-registered, and the platform is
            // built for them too.
            'cac_number' => $type === BusinessType::RegisteredCompany
                ? 'RC'.fake()->numerify('#######')
                : fake()->optional(0.3)->numerify('BN#######'),

            'business_type' => $type,
            'address' => fake()->buildingNumber().' '.fake()->streetName().' Road',
            'state' => fake()->randomElement(Nigeria::states()),
            'lga' => fake()->city(),
            'phone' => '0'.fake()->numerify('80########'),
            'whatsapp' => '0'.fake()->numerify('80########'),
            'id_document' => 'seller-documents/'.Str::random(20).'.jpg',
            'logo' => null,
            'description' => fake()->paragraph(3),
            'status' => SellerStatus::Pending,
            'submitted_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => SellerStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'The ID document was not readable.'): static
    {
        return $this->state(fn (): array => [
            'status' => SellerStatus::Rejected,
            'review_notes' => $reason,
            'reviewed_at' => now(),
        ]);
    }

    public function needsMoreInfo(string $notes = 'Please upload a clearer photograph of your ID.'): static
    {
        return $this->state(fn (): array => [
            'status' => SellerStatus::NeedsMoreInfo,
            'review_notes' => $notes,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * An administrator has decided this seller's listings never queue.
     */
    public function trusted(): static
    {
        return $this->approved()->state(fn (): array => ['auto_approve_products' => true]);
    }

    /**
     * An administrator has put this seller back under review regardless of
     * track record.
     */
    public function alwaysReviewed(): static
    {
        return $this->approved()->state(fn (): array => ['auto_approve_products' => false]);
    }
}
