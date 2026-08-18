<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "From 10 bags, ₦17,800 each." Bulk pricing is how feed and day-old chicks
 * are really sold in this market.
 */
#[Fillable(['min_quantity', 'unit_price_kobo'])]
class ProductPriceTier extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'unit_price_kobo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unitPriceNaira(): float
    {
        return $this->unit_price_kobo / 100;
    }
}
