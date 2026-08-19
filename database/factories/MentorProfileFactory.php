<?php

namespace Database\Factories;

use App\Enums\ContactMethod;
use App\Enums\MentorStatus;
use App\Models\MentorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MentorProfile>
 */
class MentorProfileFactory extends Factory
{
    protected $model = MentorProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'headline' => $this->faker->randomElement([
                'Poultry farm manager, 14 years on commercial layer farms',
                'Feed miller and nutritionist',
                'Veterinarian working with small poultry farms',
                'Fish farmer and hatchery operator',
            ]),
            'bio' => $this->faker->paragraph(4),
            'strengths' => $this->faker->paragraph(3),
            'years_experience' => $this->faker->numberBetween(3, 25),
            'qualifications' => $this->faker->sentence(),
            'affiliation' => $this->faker->company(),
            'preferred_contact_method' => ContactMethod::Whatsapp,
            'contact_value' => '0803'.$this->faker->numerify('#######'),
            'states_served' => $this->faker->randomElements(
                ['Oyo', 'Lagos', 'Ogun', 'Kaduna', 'Kano', 'Anambra', 'Plateau', 'Kwara'],
                $this->faker->numberBetween(1, 3),
            ),
            'accepts_remote' => true,
            'accepts_in_person' => $this->faker->boolean(60),
            'status' => MentorStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => MentorStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => MentorStatus::Suspended]);
    }
}
