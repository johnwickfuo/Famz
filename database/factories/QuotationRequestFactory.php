<?php

namespace Database\Factories;

use App\Enums\QuotationPowerSituation;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationScope;
use App\Enums\QuotationWaterSource;
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
            // A list of enum values, because the model casts this to an array.
            // A string here survives the insert and then blows up whichever
            // screen first tries to label it.
            'scope_wanted' => collect(QuotationScope::cases())
                ->shuffle()
                ->take(fake()->numberBetween(2, 4))
                ->map(fn (QuotationScope $scope): string => $scope->value)
                ->values()
                ->all(),
            'power_situation' => fake()->randomElement(QuotationPowerSituation::cases()),
            'water_source' => fake()->randomElement(QuotationWaterSource::cases()),
            'status' => QuotationRequestStatus::Submitted,
        ];
    }
}
