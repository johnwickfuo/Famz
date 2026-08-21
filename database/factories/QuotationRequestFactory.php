<?php

namespace Database\Factories;

use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Models\QuotationRequest;
use App\Support\Nigeria;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuotationRequest>
 */
class QuotationRequestFactory extends Factory
{
    protected $model = QuotationRequest::class;

    public function definition(): array
    {
        return [
            'reference' => 'QR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'user_id' => null,
            'project_type' => fake()->randomElement(QuotationProjectType::cases()),
            'farm_type' => fake()->randomElement(['Poultry', 'Fish', 'Mixed livestock']),
            'target_capacity' => fake()->numberBetween(500, 20000),
            'capacity_unit' => 'birds',
            'owns_land' => fake()->boolean(70),
            'land_size' => fake()->numberBetween(1, 10),
            'land_unit' => 'acres',
            'state' => fake()->randomElement(Nigeria::states()),
            'lga' => fake()->city(),
            'budget_range_min_kobo' => 200_000_000,
            'budget_range_max_kobo' => 500_000_000,
            'currency' => 'NGN',
            'scope_wanted' => fake()->paragraph(2),
            'power_situation' => fake()->randomElement(\App\Enums\QuotationPowerSituation::cases()),
            'water_source' => fake()->randomElement(\App\Enums\QuotationWaterSource::cases()),
            'status' => QuotationRequestStatus::Submitted,
        ];
    }
}
