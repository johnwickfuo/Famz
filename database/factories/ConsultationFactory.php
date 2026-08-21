<?php

namespace Database\Factories;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Models\Consultation;
use App\Support\Nigeria;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    protected $model = Consultation::class;

    public function definition(): array
    {
        $state = fake()->randomElement(Nigeria::states());

        return [
            'reference' => 'CON-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            // Null by default: most consultations are booked by a guest with no
            // account, which is the case most likely to be got wrong.
            'user_id' => null,
            'full_name' => fake()->name(),
            'phone' => '080'.fake()->numerify('########'),
            'email' => fake()->safeEmail(),
            'tier' => ConsultationTier::Standard,
            'category' => fake()->randomElement([
                'Poultry health', 'Feed and nutrition', 'Housing and equipment',
                'Fish farming', 'Starting a farm',
            ]),
            'situation' => fake()->paragraph(3),
            'farm_type' => fake()->randomElement(['Poultry', 'Fish', 'Mixed']),
            'animal_type' => fake()->randomElement(['Broilers', 'Layers', 'Catfish']),
            'flock_size' => fake()->numberBetween(50, 5000),
            'state' => $state,
            'lga' => fake()->city(),
            'status' => ConsultationStatus::Submitted,
            'currency' => 'NGN',
        ];
    }

    public function urgent(): static
    {
        return $this->state(fn (): array => ['tier' => ConsultationTier::Urgent]);
    }

    public function quoted(int $kobo = 2_500_000): static
    {
        return $this->state(fn (): array => [
            'status' => ConsultationStatus::Quoted,
            'quoted_amount_kobo' => $kobo,
            'quoted_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ConsultationStatus::Completed,
            'paid_at' => now()->subDays(3),
            'completed_at' => now()->subDay(),
        ]);
    }
}
