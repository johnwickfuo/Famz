<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\BillingType;
use App\Models\MentorProfile;
use App\Models\MentorshipPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MentorshipPackage>
 */
class MentorshipPackageFactory extends Factory
{
    protected $model = MentorshipPackage::class;

    public function definition(): array
    {
        return [
            'mentor_profile_id' => MentorProfile::factory(),
            'title' => $this->faker->randomElement([
                'One-hour farm review call',
                'Farm visit and written report',
                'Monthly hand-holding',
                'Start-up planning session',
            ]),
            'description' => $this->faker->paragraph(3),
            'billing_type' => BillingType::OneTime,
            'price_kobo' => $this->faker->numberBetween(20, 200) * 100_000,
            'currency' => 'NGN',
            'duration_description' => '1 hour',
            'sessions_included' => 1,
            'deliverables' => ['A written summary afterwards'],
            'is_active' => true,
        ];
    }

    public function periodic(BillingInterval $interval = BillingInterval::Monthly): static
    {
        return $this->state(fn (): array => [
            'billing_type' => BillingType::Periodic,
            'billing_interval' => $interval,
            'duration_description' => __('Ongoing'),
            'sessions_included' => 4,
        ]);
    }

    public function pricedAt(int $kobo): static
    {
        return $this->state(fn (): array => ['price_kobo' => $kobo]);
    }
}
