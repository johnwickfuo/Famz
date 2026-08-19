<?php

namespace Database\Factories;

use App\Enums\WithdrawalStatus;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Withdrawal>
 */
class WithdrawalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'payout_account_id' => PayoutAccount::factory()->for($user),
            'reference' => Withdrawal::newReference(),
            'amount_kobo' => fake()->numberBetween(500_000, 20_000_000),
            'currency' => 'NGN',
            'status' => WithdrawalStatus::Requested,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => WithdrawalStatus::Approved,
            'approved_at' => now(),
        ]);
    }
}
