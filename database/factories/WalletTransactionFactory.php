<?php

namespace Database\Factories;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => LedgerType::Sale,
            'amount_kobo' => fake()->numberBetween(100_000, 5_000_000),
            'state' => LedgerState::Released,
            'description' => 'Sale',
        ];
    }

    public function platform(): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'type' => LedgerType::Commission]);
    }

    public function held(): static
    {
        return $this->state(fn (): array => ['state' => LedgerState::Held]);
    }
}
