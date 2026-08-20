<?php

namespace Database\Factories;

use App\Enums\JobListingStatus;
use App\Enums\JobType;
use App\Enums\PayPeriod;
use App\Models\EmployerProfile;
use App\Models\JobListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobListing>
 */
class JobListingFactory extends Factory
{
    protected $model = JobListing::class;

    public function definition(): array
    {
        return [
            'employer_profile_id' => EmployerProfile::factory(),
            'title' => fake()->randomElement([
                'Poultry attendant',
                'Farm supervisor',
                'Brooding hand',
                'Night security',
                'Fish pond attendant',
            ]),
            'description' => fake()->paragraph(4),
            'job_type' => fake()->randomElement(JobType::cases()),
            'positions_available' => fake()->numberBetween(1, 4),
            'state' => fake()->randomElement(['Oyo', 'Kano', 'Ogun', 'Kaduna', 'Enugu']),
            'lga' => fake()->randomElement(['Akinyele', 'Ido', 'Dala', 'Ifo']),
            'is_accommodation_provided' => fake()->boolean(50),
            'is_food_provided' => fake()->boolean(40),
            'pay_min_kobo' => 4_000_000,
            'pay_max_kobo' => 7_000_000,
            'pay_period' => PayPeriod::Monthly,
            'start_date' => now()->addWeeks(2)->toDateString(),
            'application_deadline' => now()->addWeeks(4)->toDateString(),
            'status' => JobListingStatus::Draft,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (): array => [
            'status' => JobListingStatus::Open,
            'published_at' => now(),
        ]);
    }

    public function lapsed(): static
    {
        return $this->state(fn (): array => [
            'status' => JobListingStatus::Open,
            'published_at' => now()->subMonth(),
            'application_deadline' => now()->subDay()->toDateString(),
        ]);
    }
}
