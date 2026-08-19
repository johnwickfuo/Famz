<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Branding\BrandingKey;
use App\Services\Settings\SettingsService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Rules the administrator can tune without a deploy.
     *
     * @var array<string, array{type: string, value: string|null}>
     */
    private const PLATFORM_RULES = [
        'marketplace_commission_percent' => ['type' => 'float', 'value' => '5'],
        'consultation_standard_response_hours' => ['type' => 'int', 'value' => '48'],
        'consultation_urgent_response_hours' => ['type' => 'int', 'value' => '6'],
        'buyer_request_expiry_days' => ['type' => 'int', 'value' => '14'],
        'quote_validity_days' => ['type' => 'int', 'value' => '30'],
        'settlement_driver' => ['type' => 'string', 'value' => 'escrow'],
        'active_payment_gateway' => ['type' => 'string', 'value' => 'paystack'],

        // Days after the seller marks a delivery before escrow releases on its
        // own, if the buyer has said nothing and raised no dispute.
        'escrow_auto_release_days' => ['type' => 'int', 'value' => '7'],

        // The quote-a-delivery-price conversation is not built yet; the schema
        // carries it so no migration is needed when it is.
        'delivery_quotes_enabled' => ['type' => 'bool', 'value' => '0'],

        /*
         * Payouts.
         *
         * `manual_request` by default: while a platform is young somebody
         * should look at every naira that leaves it. `scheduled_auto` sweeps
         * every balance over the minimum on `payout_schedule_day` instead.
         */
        'payout_mode' => ['type' => 'string', 'value' => 'manual_request'],

        // ₦5,000. Below this the transfer fee eats the payout.
        'minimum_withdrawal_amount' => ['type' => 'int', 'value' => '500000'],

        // Day of the month the automatic sweep runs. 29–31 is clamped to the
        // last day, so February still gets paid.
        'payout_schedule_day' => ['type' => 'int', 'value' => '28'],

        // How long after a delivery is marked a buyer may still dispute it.
        // Deliberately not shorter than the escrow window: a buyer must never
        // lose the right to complain before the money has gone.
        'dispute_window_days' => ['type' => 'int', 'value' => '7'],
    ];

    public function run(): void
    {
        foreach (self::PLATFORM_RULES as $key => $definition) {
            $this->seed($key, 'platform', $definition['type'], $definition['value']);
        }

        // Branding is seeded EMPTY on purpose. The company has not been named
        // yet; the administrator fills these in from /admin, and until then
        // BrandingService falls back to config('app.name').
        foreach (BrandingKey::cases() as $key) {
            $this->seed($key->value, BrandingKey::GROUP, $key->type(), null);
        }

        app(SettingsService::class)->flush();
    }

    private function seed(string $key, string $group, string $type, ?string $value): void
    {
        // firstOrCreate, not updateOrCreate: re-running the seeder must never
        // stamp on a value the administrator has already set.
        Setting::query()->firstOrCreate(
            ['key' => $key],
            ['group' => $group, 'type' => $type, 'value' => $value],
        );
    }
}
