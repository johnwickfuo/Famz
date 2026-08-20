<?php

namespace Database\Factories;

use App\Enums\PayPeriod;
use App\Enums\WorkerAvailability;
use App\Enums\WorkTypeWanted;
use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerProfile>
 */
class WorkerProfileFactory extends Factory
{
    protected $model = WorkerProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'full_name' => fake()->name(),
            'phone' => '080'.fake()->numerify('########'),
            'whatsapp' => '080'.fake()->numerify('########'),
            'state' => fake()->randomElement(['Oyo', 'Kano', 'Ogun', 'Kaduna', 'Enugu']),
            'lga' => fake()->randomElement(['Akinyele', 'Ido', 'Dala', 'Ifo']),
            'willing_to_relocate' => fake()->boolean(40),
            'work_type_wanted' => fake()->randomElement(WorkTypeWanted::cases()),
            'years_experience' => fake()->numberBetween(0, 15),
            'expected_pay_min_kobo' => 4_000_000,
            'expected_pay_max_kobo' => 8_000_000,
            'pay_period' => PayPeriod::Monthly,
            'availability' => fake()->randomElement(WorkerAvailability::cases()),
            'about' => fake()->sentence(12),
            'is_open_to_work' => true,
            'is_active' => true,
        ];
    }

    public function relocating(): static
    {
        return $this->state(fn (): array => ['willing_to_relocate' => true]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['is_open_to_work' => false]);
    }
}
