<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One billing period, or the whole of a one-off.
 *
 * Every mentorship ledger entry hangs off one of these. Giving a one-time
 * engagement an invoice as well is what collapses "paid once" and "paid every
 * month" into a single path: money arrives against an invoice, is held against
 * that invoice, and is released against that invoice. There is no second
 * mechanism to keep in step.
 */
class MentorshipInvoice extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'sequence' => 'integer',
            'amount_kobo' => 'integer',
            'platform_amount_kobo' => 'integer',
            'mentor_amount_kobo' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            $invoice->reference ??= static::newReference();
            $invoice->status ??= InvoiceStatus::PendingPayment;
        });
    }

    public static function newReference(): string
    {
        do {
            $reference = 'MEN-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * @return BelongsTo<MentorshipEngagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(MentorshipEngagement::class, 'mentorship_engagement_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'mentorship_invoice_id');
    }

    public function amount(): string
    {
        return Money::fromKobo($this->amount_kobo);
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function periodLabel(): string
    {
        if ($this->period_start === null) {
            return __('The whole engagement');
        }

        return __(':from to :to', [
            'from' => $this->period_start->format('j M Y'),
            'to' => $this->period_end?->format('j M Y') ?? __('open'),
        ]);
    }

    public function scopePayable(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::PendingPayment);
    }
}
