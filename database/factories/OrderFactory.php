<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\Nigeria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reference' => Order::newReference(),
            'subtotal_kobo' => 0,
            'delivery_total_kobo' => 0,
            'grand_total_kobo' => 0,
            'currency' => 'NGN',
            'status' => OrderStatus::PendingPayment,
            'delivery_name' => fake()->name(),
            'delivery_phone' => '0'.fake()->numerify('80########'),
            'delivery_address' => fake()->buildingNumber().' '.fake()->streetName().' Road',
            'delivery_state' => fake()->randomElement(Nigeria::states()),
            'delivery_lga' => fake()->city(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'payment_gateway' => 'paystack',
            'gateway_reference' => 'PS-'.fake()->unique()->numerify('##########'),
        ]);
    }
}
