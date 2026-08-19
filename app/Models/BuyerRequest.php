<?php

namespace App\Models;

use App\Enums\BuyerRequestStatus;
use App\Enums\OfferStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A wanted ad.
 *
 * Reviewed before it is published: an unmoderated board fills with phone
 * numbers and scams faster than anything else on a marketplace, and a buyer
 * whose ad was checked by a person is a buyer sellers will answer.
 */
#[Fillable([
    'title', 'description', 'category_id', 'quantity', 'unit',
    'budget_min_kobo', 'budget_max_kobo', 'delivery_state', 'delivery_lga',
    'needed_by', 'accepts_partial_fulfilment', 'images',
])]
class BuyerRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => BuyerRequestStatus::class,
            'quantity' => 'integer',
            'budget_min_kobo' => 'integer',
            'budget_max_kobo' => 'integer',
            'accepts_partial_fulfilment' => 'boolean',
            'images' => 'array',
            'needed_by' => 'date',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_warned_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->reference ??= static::newReference();
            $request->slug ??= static::uniqueSlug($request->title);
        });
    }

    public static function newReference(): string
    {
        do {
            $reference = 'REQ-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug(Str::limit($title, 60, ''));
        $base = $base === '' ? 'request' : $base;
        $slug = $base;
        $suffix = 1;

        while (static::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return MorphMany<Offer, $this>
     */
    public function offers(): MorphMany
    {
        return $this->morphMany(Offer::class, 'offerable');
    }

    /**
     * @return MorphMany<Offer, $this>
     */
    public function liveOffers(): MorphMany
    {
        return $this->offers()->open();
    }

    public function acceptedOffer(): ?Offer
    {
        return $this->offers()->where('status', OfferStatus::Accepted)->latest('id')->first();
    }

    public function isOpen(): bool
    {
        return $this->status->acceptsOffers() && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * What the buyer says they will pay.
     */
    public function budgetLabel(): ?string
    {
        return match (true) {
            $this->budget_min_kobo === null && $this->budget_max_kobo === null => null,
            $this->budget_max_kobo === null => __('From :amount', ['amount' => Money::fromKobo($this->budget_min_kobo)]),
            $this->budget_min_kobo === null => __('Up to :amount', ['amount' => Money::fromKobo($this->budget_max_kobo)]),
            $this->budget_min_kobo === $this->budget_max_kobo => Money::fromKobo($this->budget_min_kobo),
            default => Money::fromKobo($this->budget_min_kobo).' – '.Money::fromKobo($this->budget_max_kobo),
        };
    }

    public function location(): string
    {
        return trim($this->delivery_lga.', '.$this->delivery_state, ', ');
    }

    /**
     * @return array<int, string>
     */
    public function imageUrls(): array
    {
        return collect($this->images ?? [])
            ->map(fn (string $path): string => Storage::disk('public')->url($path))
            ->all();
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BuyerRequestStatus::Open,
            BuyerRequestStatus::OfferAccepted,
        ]);
    }

    public function scopeAcceptingOffers(Builder $query): Builder
    {
        return $query
            ->where('status', BuyerRequestStatus::Open)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function scopeOwnedBy(Builder $query, User|int|null $user): Builder
    {
        return $query->where(
            'buyer_requests.user_id',
            $user instanceof User ? $user->getKey() : ($user ?? 0),
        );
    }

    public function scopeDueToExpire(Builder $query): Builder
    {
        return $query
            ->where('status', BuyerRequestStatus::Open)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
