<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One week of a published feeding table.
 *
 * The assistant's entire licence to state a feed figure rests on rows like
 * this. The model is told, in the system prompt, that it may not recall or
 * calculate numbers; it is handed these, and it cites them.
 */
#[Fillable([
    'species', 'breed', 'production_type', 'week_number',
    'avg_feed_g_per_bird_per_day', 'cumulative_feed_kg', 'target_weight_g',
    'water_multiplier', 'notes', 'source', 'is_active',
])]
class BreedStandard extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'week_number' => 'integer',
            'avg_feed_g_per_bird_per_day' => 'float',
            'cumulative_feed_kg' => 'float',
            'target_weight_g' => 'integer',
            'water_multiplier' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Case-insensitive breed lookup.
     *
     * Somebody types "ross 308", "Ross308" or "ROSS 308" and means the same
     * bird. A lookup that only matched the seeded casing would send most real
     * questions down the "no data" path.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForBreed(Builder $query, string $breed): void
    {
        $query->whereRaw('LOWER(REPLACE(breed, " ", "")) = ?', [
            str_replace(' ', '', mb_strtolower(trim($breed))),
        ]);
    }

    /**
     * How this row describes itself in a context block.
     */
    public function citation(): string
    {
        return trim(($this->source ?: __('platform reference table')).', '.__('week :week', ['week' => $this->week_number]));
    }
}
