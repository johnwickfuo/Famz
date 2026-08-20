<?php

namespace App\Models;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A buyer saying something went wrong.
 *
 * While one of these is live the money is frozen: escrow cannot auto-release
 * and nobody can be paid. That freeze is the entire reason a dispute exists, so
 * `isLive()` is consulted before anything releases funds.
 *
 * A dispute has exactly one subject — a sub-order or a mentorship engagement.
 * The thread, the statuses, the admin queue and the conservation law are the
 * same for both; only the arithmetic of a refund differs, and that lives in the
 * service rather than here.
 */
#[Fillable(['reason', 'description', 'evidence_images'])]
class Dispute extends Model
{
    use HasFactory, RecordsActivity;
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
     * @return BelongsTo<MentorshipEngagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(MentorshipEngagement::class, 'mentorship_engagement_id');
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

    public function isMarketplace(): bool
    {
        return $this->sub_order_id !== null;
    }

    public function isMentorship(): bool
    {
        return $this->mentorship_engagement_id !== null;
    }

    /**
     * The thing being argued about, whichever kind it is.
     */
    public function subject(): SubOrder|MentorshipEngagement|null
    {
        return $this->isMentorship() ? $this->engagement : $this->subOrder;
    }

    /**
     * The reference a human would quote down the phone.
     */
    public function subjectReference(): string
    {
        return (string) ($this->subject()?->reference ?? '—');
    }

    /**
     * What is actually at stake.
     *
     * On a sub-order that is the seller's part with delivery included, because
     * a buyer who got nothing paid for the delivery too. On mentorship it is
     * what has actually been paid so far — on a monthly engagement, arguing
     * about month three does not put months one and two back in play.
     */
    public function amountAtStakeKobo(): int
    {
        if ($this->isMentorship()) {
            return $this->engagement?->paidToDateKobo() ?? 0;
        }

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

    public function scopeMarketplace(Builder $query): Builder
    {
        return $query->whereNotNull('sub_order_id');
    }

    public function scopeMentorship(Builder $query): Builder
    {
        return $query->whereNotNull('mentorship_engagement_id');
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

    /**
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['status', 'resolution', 'resolved_by'];
    }
}
