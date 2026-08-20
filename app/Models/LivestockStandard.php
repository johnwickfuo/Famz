<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A feeding reference for everything that is not poultry.
 *
 * Shaped differently on purpose: nobody asks what a goat eats in week
 * nineteen. They ask what it eats a day at a given weight, and ruminant and
 * fish rations are quoted as a percentage of body weight rather than a flat
 * figure.
 */
#[Fillable([
    'species', 'category', 'typical_weight_kg', 'feed_kg_per_day',
    'feed_percent_of_bodyweight', 'water_litres_per_day', 'notes', 'source',
    'is_active',
])]
class LivestockStandard extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'typical_weight_kg' => 'integer',
            'feed_kg_per_day' => 'float',
            'feed_percent_of_bodyweight' => 'float',
            'water_litres_per_day' => 'float',
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

    public function citation(): string
    {
        return $this->source ?: __('platform reference table');
    }
}
