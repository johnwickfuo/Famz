<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\SubOrderStatus;
use App\Support\Commission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One seller's part of an order.
 *
 * This is where the money is actually split: the seller's subtotal, the
 * commission taken at the rate in force when the buyer paid, and the payout the
 * seller is owed. All three are snapshotted, so changing the platform's
 * commission never rewrites what a seller was promised on work already done.
 */
class SubOrder extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => SubOrderStatus::class,
            'delivery_method' => DeliveryMethod::class,
            'subtotal_kobo' => 'integer',
            'commission_amount_kobo' => 'integer',
            'seller_payout_amount_kobo' => 'integer',
            'delivery_fee_kobo' => 'integer',
            'commission_percent_snapshot' => 'decimal:2',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'received_at' => 'datetime',
            'disputed_at' => 'datetime',
            'settled_at' => 'datetime',
            'auto_release_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => SubOrderStatus::Pending->value,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $subOrder): void {
            $subOrder->reference ??= static::newReference();
        });
    }

    public static function newReference(): string
    {
        do {
            $reference = 'SO-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function scopeForSeller(Builder $query, ?SellerProfile $seller): Builder
    {
        // Fails closed, like every other ownership scope on this platform.
        return $query->where('sub_orders.seller_id', $seller?->getKey() ?? 0);
    }

    public function scopeAwaitingAutoRelease(Builder $query): Builder
    {
        return $query
            ->where('status', SubOrderStatus::Delivered)
            ->whereNotNull('auto_release_at')
            ->where('auto_release_at', '<=', now())
            ->whereNull('settled_at');
    }

    /**
     * What the buyer paid for this seller's part, delivery included.
     */
    public function grandTotalKobo(): int
    {
        return $this->subtotal_kobo + $this->delivery_fee_kobo;
    }

    /**
     * Everything credited to the seller for this order.
     *
     * The delivery fee is theirs in full: it pays for their fuel and their
     * afternoon, and commission is charged on goods, not on transport. Leaving
     * it out — as this did until the payout layer was built — meant the buyer
     * paid for delivery and nobody was ever credited with it, so the ledger
     * could never be reconciled against what the gateway actually settled.
     */
    public function sellerCreditKobo(): int
    {
        return $this->seller_payout_amount_kobo + $this->delivery_fee_kobo;
    }

    /**
     * The split, recomputed from the snapshot. Used by the tests to assert the
     * stored figures are internally consistent rather than merely plausible.
     */
    public function commissionSplit(): Commission
    {
        return Commission::on($this->subtotal_kobo, (float) $this->commission_percent_snapshot);
    }

    public function balances(): bool
    {
        return $this->commission_amount_kobo + $this->seller_payout_amount_kobo === $this->subtotal_kobo;
    }

    public function isDisputed(): bool
    {
        return $this->status === SubOrderStatus::Disputed;
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * Delivery is only ever chargeable when the seller is doing the delivering.
     */
    public function requiresSellerDelivery(): bool
    {
        return $this->delivery_method === DeliveryMethod::SellerArranged;
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }
}
