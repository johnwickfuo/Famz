<?php

namespace Database\Factories;

use App\Enums\DeliveryMethod;
use App\Enums\SubOrderStatus;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Support\Commission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubOrder>
 */
class SubOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(100_000, 50_000_000);
        $split = Commission::on($subtotal, 5);

        return [
            'order_id' => Order::factory(),
            'seller_id' => SellerProfile::factory()->approved(),
            'reference' => SubOrder::newReference(),
            'subtotal_kobo' => $split->subtotalKobo,
            'commission_percent_snapshot' => $split->percent(),
            'commission_amount_kobo' => $split->commissionKobo,
            'seller_payout_amount_kobo' => $split->payoutKobo,
            'delivery_method' => DeliveryMethod::BuyerPickup,
            'delivery_fee_kobo' => 0,
            'status' => SubOrderStatus::Pending,
        ];
    }

    public function pricedAt(int $subtotalKobo, float $commissionPercent = 5.0): static
    {
        $split = Commission::on($subtotalKobo, $commissionPercent);

        return $this->state(fn (): array => [
            'subtotal_kobo' => $split->subtotalKobo,
            'commission_percent_snapshot' => $split->percent(),
            'commission_amount_kobo' => $split->commissionKobo,
            'seller_payout_amount_kobo' => $split->payoutKobo,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => SubOrderStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => SubOrderStatus::Delivered,
            'accepted_at' => now()->subDays(3),
            'shipped_at' => now()->subDays(2),
            'delivered_at' => now(),
        ]);
    }
}
