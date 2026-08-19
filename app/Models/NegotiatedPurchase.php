<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The right to buy something at a price that was agreed rather than listed.
 *
 * Created when an offer is accepted; carries a token that makes a private
 * checkout link, and holds stock back for the buyer while the link is live.
 * A reservation nobody uses is released by the scheduled sweep, because stock
 * held for a buyer who has gone away is stock nobody can sell.
 */
class NegotiatedPurchase extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_kobo' => 'integer',
            'reserved_quantity' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $purchase): void {
            $purchase->token ??= Str::random(48);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id');
    }

    /**
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

    /**
     * @return BelongsTo<BuyerRequest, $this>
     */
    public function buyerRequest(): BelongsTo
    {
        return $this->belongsTo(BuyerRequest::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && ! $this->hasExpired();
    }

    public function totalKobo(): int
    {
        return $this->unit_price_kobo * $this->quantity;
    }

    /**
     * Reservations that have run out but are still holding stock.
     */
    public function scopeDueToRelease(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->where('reserved_quantity', '>', 0)
            ->where('expires_at', '<=', now());
    }
}
