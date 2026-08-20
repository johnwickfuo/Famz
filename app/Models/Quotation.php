<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One version of a proposal.
 *
 * Written by hand. There is no pricing engine and there is not meant to be one:
 * what it costs to put up a 20,000-bird layer house in Oyo depends on the site,
 * the season, who is supplying the sheet and what the client already owns. A
 * formula that pretended otherwise would produce numbers the company could not
 * stand behind.
 *
 * Never edited once sent. A revision is a new row at the next version number
 * and the old one is superseded, so the document a client is holding keeps
 * saying exactly what it said.
 */
#[Fillable([
    'title', 'executive_summary', 'scope_of_work', 'assumptions', 'exclusions',
    'timeline_description', 'payment_terms', 'contingency_percent',
])]
class Quotation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'version' => 'integer',
            'subtotal_kobo' => 'integer',
            'contingency_percent' => 'decimal:2',
            'contingency_kobo' => 'integer',
            'total_kobo' => 'integer',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $quotation): void {
            $quotation->status ??= QuotationStatus::Draft;
            $quotation->version ??= 1;
            $quotation->currency ??= 'NGN';
        });
    }

    // -----------------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------------

    /**
     * @return BelongsTo<QuotationRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(QuotationRequest::class, 'quotation_request_id');
    }

    /**
     * @return HasMany<QuotationLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(QuotationLineItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    public function isDraft(): bool
    {
        return $this->status === QuotationStatus::Draft;
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Whether the client could still act on this today.
     *
     * Checks the date as well as the status, because the expiry command runs on
     * a schedule and a page load can land between a proposal lapsing and the
     * sweep noticing. Better to tell somebody it has lapsed a few hours early
     * than to let them ring up quoting a price the company has withdrawn.
     */
    public function isLive(?Carbon $at = null): bool
    {
        if ($this->status !== QuotationStatus::Sent) {
            return false;
        }

        return ! $this->hasLapsed($at);
    }

    public function hasLapsed(?Carbon $at = null): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        return $this->valid_until->endOfDay()->isBefore($at ?? now());
    }

    /**
     * How long the client has left, in words.
     */
    public function validityCountdown(?Carbon $at = null): ?string
    {
        if ($this->valid_until === null) {
            return null;
        }

        $at ??= now();

        if ($this->hasLapsed($at)) {
            return __('Lapsed :when', ['when' => $this->valid_until->endOfDay()->diffForHumans($at)]);
        }

        $days = (int) $at->startOfDay()->diffInDays($this->valid_until->startOfDay(), absolute: false);

        return match (true) {
            $days <= 0 => __('Last day'),
            $days === 1 => __('1 day left'),
            default => __(':count days left', ['count' => $days]),
        };
    }

    /**
     * Whether it is close enough to lapsing to be worth saying so loudly.
     */
    public function isLapsingSoon(int $withinDays = 7, ?Carbon $at = null): bool
    {
        if ($this->valid_until === null || ! $this->isLive($at)) {
            return false;
        }

        $at ??= now();

        return $this->valid_until->startOfDay()->diffInDays($at->startOfDay(), absolute: true) <= $withinDays;
    }

    // -----------------------------------------------------------------------
    // Money
    // -----------------------------------------------------------------------

    /**
     * Recompute the totals from the lines that are actually there.
     *
     * Kept as an explicit call rather than an accessor so that a sent
     * quotation's stored totals are never quietly recalculated underneath it.
     */
    public function recalculate(): static
    {
        $subtotal = (int) $this->lineItems()->sum('total_kobo');

        // Rounded to the naira, not the kobo: a contingency is an estimate of
        // an estimate, and quoting it to the kobo is false precision.
        $contingency = (int) round($subtotal * ((float) $this->contingency_percent) / 100 / 100) * 100;

        $this->forceFill([
            'subtotal_kobo' => $subtotal,
            'contingency_kobo' => $contingency,
            'total_kobo' => $subtotal + $contingency,
        ]);

        return $this;
    }

    public function subtotal(): string
    {
        return Money::fromKobo($this->subtotal_kobo);
    }

    public function contingency(): string
    {
        return Money::fromKobo($this->contingency_kobo);
    }

    public function total(): string
    {
        return Money::fromKobo($this->total_kobo);
    }

    /**
     * The lines grouped for printing, in the admin's own order.
     *
     * Sections come out in the order their first line appears rather than
     * alphabetically, because an administrator who put Site preparation before
     * Housing meant the work to read in that order.
     *
     * @return Collection<string, EloquentCollection<int, QuotationLineItem>>
     */
    public function sections(): Collection
    {
        return $this->lineItems
            ->groupBy(fn (QuotationLineItem $item): string => $item->section ?: __('Other'));
    }

    /**
     * @return array<string, int>
     */
    public function sectionTotals(): array
    {
        return $this->sections()
            ->map(fn (EloquentCollection $items): int => (int) $items->sum('total_kobo'))
            ->all();
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * Sent, dated, and past that date. What the expiry sweep looks for.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLapsed(Builder $query, ?Carbon $at = null): void
    {
        $query
            ->where('status', QuotationStatus::Sent)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', ($at ?? now())->toDateString());
    }
}
