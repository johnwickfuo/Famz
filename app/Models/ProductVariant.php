<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A size or weight option — "25kg bag" against "50kg bag", "Pullet" against
 * "Cockerel". The delta is signed, so a smaller option can cost less.
 */
#[Fillable(['name', 'price_delta_kobo', 'stock_quantity', 'sku', 'sort_order'])]
class ProductVariant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price_delta_kobo' => 'integer',
            'stock_quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceKobo(): int
    {
        return max(0, $this->product->price_kobo + $this->price_delta_kobo);
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }
}
