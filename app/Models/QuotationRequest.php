<?php

namespace App\Models;

use App\Enums\QuotationPowerSituation;
use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationScope;
use App\Enums\QuotationStatus;
use App\Enums\QuotationWaterSource;
use App\Enums\StudyFeeCreditStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Somebody asking what it would cost to set up a farm.
 *
 * Longer than every other form on the platform, and deliberately so. A farmer
 * with dying birds gets four fields; somebody contemplating spending twenty
 * million naira is asked the questions a quantity surveyor would ask, because
 * nobody can price a build from a name and a phone number.
 */
#[Fillable([
    'project_type', 'farm_type', 'target_capacity', 'capacity_unit',
    'owns_land', 'land_size', 'land_unit', 'state', 'lga', 'address',
    'budget_range_min_kobo', 'budget_range_max_kobo', 'target_start_date',
    'scope_wanted', 'power_situation', 'water_source', 'additional_notes',
    'site_photos',
])]
class QuotationRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'project_type' => QuotationProjectType::class,
            'power_situation' => QuotationPowerSituation::class,
            'water_source' => QuotationWaterSource::class,
            'status' => QuotationRequestStatus::class,
            'scope_wanted' => 'array',
            'site_photos' => 'array',
            'owns_land' => 'boolean',
            'land_size' => 'decimal:2',
            'target_capacity' => 'integer',
            'budget_range_min_kobo' => 'integer',
            'budget_range_max_kobo' => 'integer',
            'target_start_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->reference ??= static::newReference();
            $request->status ??= QuotationRequestStatus::Submitted;

            /*
             * Set here rather than left to the column default. The study fee is
             * raised from this request in the same breath as creating it, and a
             * model that has not been re-read from the database does not know
             * what the database would have filled in.
             */
            $request->currency ??= 'NGN';
        });
    }

    public static function newReference(): string
    {
        do {
            $reference = 'QR-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    // -----------------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------------

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<QuotationStudyFee, $this>
     */
    public function studyFee(): HasOne
    {
        return $this->hasOne(QuotationStudyFee::class);
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->orderByDesc('version');
    }

    /**
     * Every version the client is allowed to see.
     *
     * Drafts are excluded rather than filtered in the view: a half-priced
     * proposal appearing in somebody's dashboard is worse than no proposal.
     *
     * @return HasMany<Quotation, $this>
     */
    public function visibleQuotations(): HasMany
    {
        return $this->quotations()->where('status', '!=', QuotationStatus::Draft);
    }

    /**
     * The version that counts: the newest one that has been sent.
     *
     * Not simply the highest version number — that could be a draft revision
     * being written right now, which the client must not see.
     *
     * @return HasOne<Quotation, $this>
     */
    public function currentQuotation(): HasOne
    {
        /*
         * The constraint goes INSIDE ofMany, not before it. A `whereIn` chained
         * onto the relation filters the outer query but not the aggregate
         * subquery, so the subquery picks the highest version of all — often a
         * draft revision being written right now — and then the outer filter
         * removes it, leaving nothing at all.
         */
        return $this->hasOne(Quotation::class)->ofMany(
            ['version' => 'max'],
            fn (Builder $query) => $query->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Expired]),
        );
    }

    /**
     * @return HasOne<Quotation, $this>
     */
    public function latestQuotation(): HasOne
    {
        return $this->hasOne(Quotation::class)->latestOfMany('version');
    }

    // -----------------------------------------------------------------------
    // What state it is in
    // -----------------------------------------------------------------------

    public function studyFeePaid(): bool
    {
        return $this->studyFee?->isPaid() ?? false;
    }

    public function awaitsStudyFee(): bool
    {
        return $this->status->awaitsStudyFee() && ! $this->studyFeePaid();
    }

    public function isClosed(): bool
    {
        return $this->status->isClosed();
    }

    /**
     * Whether somebody owes this client a proposal.
     *
     * The single gate this whole module turns on: everything else about the
     * request can be perfect, but without a cleared fee it is not work.
     *
     * The status test matters as much as the fee test, and must agree with
     * `scopeWorkable` exactly — the navigation badge, the "to write" tab and
     * the row tint all say the same thing, and a request whose proposal has
     * already gone out is not outstanding work however much was paid for it.
     */
    public function isWorkable(): bool
    {
        return $this->studyFeePaid() && $this->status->isPaidWork();
    }

    // -----------------------------------------------------------------------
    // Reading it back
    // -----------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    public function scopeLabels(): array
    {
        return QuotationScope::labelsFor($this->scope_wanted);
    }

    /**
     * The budget as a person would say it.
     *
     * Handles one end being missing, because plenty of people know only what
     * they can afford or only what they hope to spend.
     */
    public function budgetRange(): ?string
    {
        $min = $this->budget_range_min_kobo;
        $max = $this->budget_range_max_kobo;

        return match (true) {
            $min !== null && $max !== null => Money::compactFromKobo($min).' – '.Money::compactFromKobo($max),
            $min !== null => __('From :amount', ['amount' => Money::compactFromKobo($min)]),
            $max !== null => __('Up to :amount', ['amount' => Money::compactFromKobo($max)]),
            default => null,
        };
    }

    public function capacity(): ?string
    {
        if ($this->target_capacity === null) {
            return null;
        }

        return trim(number_format($this->target_capacity).' '.($this->capacity_unit ?? ''));
    }

    public function landSize(): ?string
    {
        if ($this->land_size === null) {
            return null;
        }

        // Trailing zeros on a land size read like false precision: two
        // hectares is "2 hectares", not "2.00 hectares".
        $size = rtrim(rtrim(number_format((float) $this->land_size, 2), '0'), '.');

        return trim($size.' '.($this->land_unit ?? ''));
    }

    /**
     * Where the site is, as a person would say it.
     *
     * Named `place` and not `where`: Eloquent forwards `where()` to the query
     * builder, so a model method by that name silently breaks route-model
     * binding and every query built off the model.
     */
    public function place(): ?string
    {
        return collect([$this->lga, $this->state])->filter()->implode(', ') ?: null;
    }

    /**
     * @return array<int, string>
     */
    public function sitePhotoUrls(): array
    {
        return collect($this->site_photos ?? [])
            ->map(fn (string $path): string => Storage::disk('public')->url($path))
            ->all();
    }

    /**
     * How the study fee ended up, for the admin's audit column.
     */
    public function creditStatus(): ?StudyFeeCreditStatus
    {
        return $this->studyFee?->credit_status;
    }

    public function belongsToUser(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->getKey();
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }

    /**
     * The admin work queue: fee cleared, nobody has closed it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWorkable(Builder $query): void
    {
        $query
            ->whereIn('status', [
                QuotationRequestStatus::StudyFeePaid,
                QuotationRequestStatus::InPreparation,
            ])
            ->whereHas('studyFee', fn (Builder $fee) => $fee->whereNotNull('paid_at'));
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingStudyFee(Builder $query): void
    {
        $query->whereIn('status', [
            QuotationRequestStatus::Submitted,
            QuotationRequestStatus::StudyFeePending,
        ]);
    }
}
