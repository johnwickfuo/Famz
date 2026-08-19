<?php

namespace App\Filament\Admin\Resources\Courses\Support;

use App\Support\Money;

/**
 * The naira-to-kobo step, in one place.
 *
 * The form asks for naira because that is what a person types; the column
 * holds kobo because that is the only way the arithmetic stays exact. Both
 * pages do the same conversion, so it lives here rather than being written
 * twice and drifting once.
 */
class CoursePricing
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toKobo(array $data): array
    {
        $free = (bool) ($data['is_free'] ?? false);

        $data['price_kobo'] = $free ? 0 : Money::toKobo($data['price_naira'] ?? 0);

        unset($data['price_naira']);

        return $data;
    }
}
