<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * One proposal in a haggle.
 *
 * The same row serves a buyer haggling over a listing and a seller answering a
 * wanted ad — they are the same transaction from opposite ends. What differs is
 * the `offerable` it points at, and who is expected to answer.
 *
 * A counter-offer is a new Offer whose `parent_offer_id` is the one it answers.
 * Nothing is ever rewritten, so `chain()` reads back the whole negotiation in
 * order.
 */
#[Fillable(['quantity', 'unit_price_kobo', 'message', 'delivery_days'])]
class Offer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'quantity' => 'integer',
            'unit_price_kobo' => 'integer',
            'total_price_kobo' => 'integer',
            'delivery_days' => 'integer',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The total is never passed in: it is the product of two fields that
        // are, and a total that could disagree with them would be worse than
        // no total at all.
        static::saving(function (self $offer): void {
            $offer->total_price_kobo = $offer->unit_price_kobo * $offer->quantity;
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function offerable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id');
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id');
    }

    /**
     * The offer this one answers.
     *
     * @return BelongsTo<Offer, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_offer_id');
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function counters(): HasMany
    {
        return $this->hasMany(self::class, 'parent_offer_id');
    }

    /**
     * @return HasOne<NegotiatedPurchase, $this>
     */
    public function purchase(): HasOne
    {
        return $this->hasOne(NegotiatedPurchase::class);
    }

    /**
     * The first offer in this haggle.
     *
     * Walks up rather than storing a root id: the chains are short — nobody
     * haggles thirty times — and a denormalised root is one more thing that
     * can be wrong.
     */
    public function root(): self
    {
        $offer = $this;

        while ($offer->parent_offer_id !== null) {
            $parent = $offer->parent;

            if ($parent === null) {
                break;
            }

            $offer = $parent;
        }

        return $offer;
    }

    /**
     * The whole haggle, oldest first.
     *
     * @return Collection<int, Offer>
     */
    public function chain(): Collection
    {
        $root = $this->root();

        $chain = collect([$root]);
        $current = $root;

        // One counter per offer by construction — the service refuses a second
        // — so this walk is a line, not a tree.
        while (($next = $current->counters()->oldest('id')->first()) !== null) {
            $chain->push($next);
            $current = $next;
        }

        return $chain;
    }

    /**
     * How far into the haggle this offer is, counting from one.
     */
    public function round(): int
    {
        $round = 1;
        $offer = $this;

        while ($offer->parent_offer_id !== null && ($offer = $offer->parent) !== null) {
            $round++;
        }

        return $round;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this offer can still be answered.
     *
     * Expiry is checked here rather than trusted from the column, because the
     * scheduled sweep that writes `expired` runs on a clock and an offer that
     * ran out a minute ago must not be acceptable in the meantime.
     */
    public function isOpen(): bool
    {
        return $this->status->isLive() && ! $this->hasExpired();
    }

    public function totalPrice(): string
    {
        return Money::fromKobo($this->total_price_kobo);
    }

    public function unitPrice(): string
    {
        return Money::fromKobo($this->unit_price_kobo);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->where('status', OfferStatus::Pending)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function scopeAwaiting(Builder $query, User|int|null $user): Builder
    {
        return $query
            ->open()
            ->where('responder_id', $user instanceof User ? $user->getKey() : ($user ?? 0));
    }

    public function scopeForSeller(Builder $query, ?SellerProfile $seller): Builder
    {
        return $query->where('offers.seller_id', $seller?->getKey() ?? 0);
    }

    /**
     * Offers that have run out but are still marked pending.
     */
    public function scopeDueToExpire(Builder $query): Builder
    {
        return $query
            ->where('status', OfferStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
