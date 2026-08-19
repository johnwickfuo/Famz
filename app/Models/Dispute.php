<?php

namespace App\Models;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A buyer saying something went wrong.
 *
 * While one of these is live the sub-order's money is frozen: escrow cannot
 * auto-release and the seller cannot be paid. That freeze is the entire reason
 * a dispute exists, so `isLive()` is consulted before anything releases funds.
 */
#[Fillable(['reason', 'description', 'evidence_images'])]
class Dispute extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'reason' => DisputeReason::class,
            'status' => DisputeStatus::class,
            'evidence_images' => 'array',
            'refund_amount_kobo' => 'integer',
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return HasMany<DisputeMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class)->oldest();
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * What is actually at stake: the seller's part of the order, delivery
     * included, because a buyer who got nothing paid for the delivery too.
     */
    public function amountAtStakeKobo(): int
    {
        return $this->subOrder->grandTotalKobo();
    }

    /**
     * @return array<int, string>
     */
    public function evidenceUrls(): array
    {
        return collect($this->evidence_images ?? [])
            ->map(fn (string $path): string => Storage::disk('public')->url($path))
            ->all();
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [DisputeStatus::Open, DisputeStatus::UnderReview]);
    }

    /**
     * Disputes on orders belonging to one seller.
     */
    public function scopeForSeller(Builder $query, ?SellerProfile $seller): Builder
    {
        return $query->whereHas(
            'subOrder',
            fn (Builder $sub) => $sub->where('seller_id', $seller?->getKey() ?? 0),
        );
    }
}
