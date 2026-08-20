<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What something cost on this platform's marketplace on a given day.
 *
 * Not scraped from anywhere. A third-party price is stale the day after it is
 * taken and unverifiable forever; these are aggregated from listings on this
 * site, so the assistant can say where a figure came from, how many sellers it
 * is based on, and when it was captured — and the answer improves as the
 * marketplace grows rather than rotting.
 */
#[Fillable([
    'category_id', 'keyword_group', 'state', 'median_price_kobo',
    'min_price_kobo', 'max_price_kobo', 'sample_size', 'unit', 'captured_at',
])]
class MarketPriceSnapshot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'median_price_kobo' => 'integer',
            'min_price_kobo' => 'integer',
            'max_price_kobo' => 'integer',
            'sample_size' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * A national snapshot, which is what a thin state sample widens to.
     */
    public function isNational(): bool
    {
        return $this->state === null;
    }

    public function median(): string
    {
        return Money::fromKobo($this->median_price_kobo);
    }

    public function range(): string
    {
        return Money::fromKobo($this->min_price_kobo).' – '.Money::fromKobo($this->max_price_kobo);
    }

    /**
     * How old this figure is, which the assistant is required to say.
     */
    public function ageInDays(): int
    {
        return (int) $this->captured_at->startOfDay()->diffInDays(now()->startOfDay(), absolute: true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeFresh(Builder $query, int $withinDays = 14): void
    {
        $query->where('captured_at', '>=', now()->subDays($withinDays));
    }
}
