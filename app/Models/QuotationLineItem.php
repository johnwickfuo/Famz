<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line on a proposal.
 *
 * `total_kobo` is written down rather than computed on read for the same reason
 * the quotation is versioned: a sent document has to keep saying what it said.
 * The model keeps it honest by recomputing on every save, so the stored value
 * can never drift from the quantity and price beside it.
 */
#[Fillable(['section', 'description', 'quantity', 'unit', 'unit_price_kobo', 'sort_order'])]
class QuotationLineItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price_kobo' => 'integer',
            'total_kobo' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Not fillable, and never taken from a form: a line whose total does
        // not match its own quantity times its own price is a document nobody
        // can defend.
        static::saving(function (self $item): void {
            $item->total_kobo = $item->computedTotalKobo();
        });
    }

    public function computedTotalKobo(): int
    {
        return (int) round(((float) $this->quantity) * $this->unit_price_kobo);
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function unitPrice(): string
    {
        return Money::fromKobo($this->unit_price_kobo);
    }

    public function total(): string
    {
        return Money::fromKobo($this->total_kobo);
    }

    /**
     * The quantity as a person would write it: "3", not "3.00"; "2.5" stays.
     */
    public function quantityLabel(): string
    {
        return rtrim(rtrim(number_format((float) $this->quantity, 2), '0'), '.');
    }
}
