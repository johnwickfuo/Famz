<?php

namespace Database\Factories;

use App\Models\MentorInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MentorInvitation>
 */
class MentorInvitationFactory extends Factory
{
    protected $model = MentorInvitation::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'expires_at' => now()->addDays(MentorInvitation::DEFAULT_DAYS),
        ];
    }

    public function addressedTo(string $email): static
    {
        return $this->state(fn (): array => ['email' => $email]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function used(?User $by = null): static
    {
        return $this->state(fn (): array => [
            'used_at' => now(),
            'used_by' => $by?->getKey() ?? User::factory(),
        ]);
    }
}
