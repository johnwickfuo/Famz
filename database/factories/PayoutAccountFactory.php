<?php

namespace Database\Factories;

use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutAccount>
 */
class PayoutAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_code' => fake()->randomElement(['058', '044', '033', '011', '057']),
            'bank_name' => fake()->randomElement([
                'Guaranty Trust Bank', 'Access Bank', 'United Bank for Africa', 'First Bank of Nigeria', 'Zenith Bank',
            ]),
            'account_number' => (string) fake()->numerify('##########'),
            'account_name' => fake()->name(),
            'is_verified' => true,
            'verified_at' => now(),
            'is_default' => true,
            'gateway' => 'paystack',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => [
            'is_verified' => false,
            'verified_at' => null,
        ]);
    }
}
