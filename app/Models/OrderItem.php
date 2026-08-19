<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A line on a receipt.
 *
 * Everything a person needs to read this line back — the name, the option, the
 * unit, the price, the quantity — is copied here at purchase. Nothing on this
 * model reads through to the live product, because a seller renaming, repricing
 * or deleting a listing must not change what last month's receipt says.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price_kobo' => 'integer',
            'quantity' => 'integer',
            'line_total_kobo' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * Present only so a buyer can click through to a listing that still exists.
     * Never read for the historical record.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function label(): string
    {
        return $this->variant_name === null
            ? $this->product_name
            : "{$this->product_name} — {$this->variant_name}";
    }

    /**
     * The listing's photograph where the product is still around, for the order
     * screens. Absence is expected and handled by the caller.
     */
    public function imageUrl(): ?string
    {
        $path = $this->product?->images->first()?->path;

        return $path === null ? null : Storage::disk(config('filesystems.default'))->url($path);
    }
}
