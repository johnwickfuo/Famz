<?php

namespace App\Services\Quotations;

use App\Models\QuotationRequest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Taking a farm setup enquiry.
 *
 * The form behind this is the longest on the platform, and deliberately so. A
 * farmer with dying birds gets four fields because speed is the product;
 * somebody contemplating spending twenty million naira is asked the questions a
 * quantity surveyor would ask, because a proposal cannot be written without
 * them and they are willing to spend ten minutes answering.
 *
 * Submitting raises the study fee immediately. There is no window in which a
 * request sits looking like work: it is an enquiry until the fee clears.
 */
class QuotationRequestService
{
    public function __construct(private readonly StudyFeeService $fees) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, User $user): QuotationRequest
    {
        return DB::transaction(function () use ($data, $user): QuotationRequest {
            $request = new QuotationRequest;

            $request->forceFill([
                'user_id' => $user->getKey(),

                'project_type' => $data['project_type'],
                'farm_type' => trim($data['farm_type']),

                'target_capacity' => $this->intOrNull($data['target_capacity'] ?? null),
                'capacity_unit' => $this->cleanOrNull($data['capacity_unit'] ?? null),

                'owns_land' => (bool) ($data['owns_land'] ?? false),
                'land_size' => $this->floatOrNull($data['land_size'] ?? null),
                'land_unit' => $this->cleanOrNull($data['land_unit'] ?? null),

                'state' => $this->cleanOrNull($data['state'] ?? null),
                'lga' => $this->cleanOrNull($data['lga'] ?? null),
                'address' => $this->cleanOrNull($data['address'] ?? null),

                // A range, because nobody knows their budget to the naira at
                // this stage and asking for one produces a made-up figure that
                // then anchors the whole proposal.
                'budget_range_min_kobo' => $this->kobo($data['budget_range_min'] ?? null),
                'budget_range_max_kobo' => $this->kobo($data['budget_range_max'] ?? null),

                'target_start_date' => $data['target_start_date'] ?? null,

                'scope_wanted' => ($data['scope_wanted'] ?? []) === [] ? null : array_values($data['scope_wanted']),

                'power_situation' => $data['power_situation'] ?? null,
                'water_source' => $data['water_source'] ?? null,

                'additional_notes' => $this->cleanOrNull($data['additional_notes'] ?? null),
                'site_photos' => ($data['site_photos'] ?? []) === [] ? null : array_values($data['site_photos']),
            ])->save();

            // Raising the fee also moves the request to study_fee_pending, so
            // it is never briefly indistinguishable from paid work.
            $this->fees->raiseFor($request);

            return $request->refresh();
        });
    }

    private function cleanOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * Naira in, kobo out. Nulls stay null rather than becoming zero — "no
     * budget stated" and "a budget of nothing" are different answers.
     */
    private function kobo(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : Money::toKobo($value);
    }
}
