<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\BillingType;
use App\Enums\EngagementStatus;
use App\Enums\InvoiceStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A client hiring a mentor.
 *
 * Two things are worth reading closely.
 *
 * First, every commercial term is a snapshot. The package it came from can be
 * repriced, renamed or deleted and this engagement still knows exactly what was
 * agreed, at what commission, for how much to whom.
 *
 * Second, the contact reveal is a function of `status` and nothing else. There
 * is no `contact_unlocked` column that could disagree with the payment, and no
 * flag a controller might forget to set: the question "may these two people see
 * each other's details" is answered by asking what state the engagement is in.
 */
class MentorshipEngagement extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => EngagementStatus::class,
            'billing_type' => BillingType::class,
            'billing_interval' => BillingInterval::class,
            'price_kobo' => 'integer',
            'platform_amount_kobo' => 'integer',
            'mentor_amount_kobo' => 'integer',
            'commission_percent_snapshot' => 'float',
            'started_at' => 'datetime',
            'mentor_marked_complete_at' => 'datetime',
            'client_confirmed_at' => 'datetime',
            'auto_confirm_at' => 'datetime',
            'auto_confirmed' => 'boolean',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $engagement): void {
            $engagement->reference ??= static::newReference();
            $engagement->status ??= EngagementStatus::PendingPayment;
        });
    }

    public static function newReference(): string
    {
        do {
            $reference = 'MTR-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * @return BelongsTo<MentorProfile, $this>
     */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(MentorProfile::class, 'mentor_profile_id');
    }

    /**
     * @return BelongsTo<MentorshipPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(MentorshipPackage::class, 'mentorship_package_id');
    }

    /**
     * @return HasMany<MentorshipInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(MentorshipInvoice::class)->orderBy('sequence');
    }

    /**
     * @return HasOne<MentorReview, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(MentorReview::class);
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function price(): string
    {
        return Money::fromKobo($this->price_kobo);
    }

    public function priceLabel(): string
    {
        if (! $this->isPeriodic()) {
            return $this->price();
        }

        return __(':price a :unit', [
            'price' => $this->price(),
            'unit' => $this->billing_interval->unitLabel(),
        ]);
    }

    public function isPeriodic(): bool
    {
        return $this->billing_type === BillingType::Periodic && $this->billing_interval !== null;
    }

    /**
     * Whether these two people may see each other's contact details.
     *
     * One question, one place, asked by everything that reveals anything.
     */
    public function revealsContact(): bool
    {
        return $this->status->revealsContact();
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * Whether the mentor is waiting on the client's word.
     */
    public function isAwaitingConfirmation(): bool
    {
        return $this->status === EngagementStatus::AwaitingConfirmation;
    }

    /**
     * Whether silence has now lasted long enough to count as agreement.
     */
    public function isDueAutoConfirmation(): bool
    {
        return $this->isAwaitingConfirmation()
            && $this->auto_confirm_at !== null
            && $this->auto_confirm_at->isPast();
    }

    public function nextInvoice(): ?MentorshipInvoice
    {
        return $this->invoices()->where('status', InvoiceStatus::PendingPayment)->orderBy('sequence')->first();
    }

    public function paidInvoices(): HasMany
    {
        return $this->invoices()->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Released]);
    }

    /**
     * Everything the client has actually paid so far, across every period.
     */
    public function paidToDateKobo(): int
    {
        return (int) $this->invoices()
            ->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Released])
            ->sum('amount_kobo');
    }

    /**
     * What is still sitting in escrow against this engagement.
     */
    public function heldKobo(): int
    {
        return (int) $this->invoices()->where('status', InvoiceStatus::Paid)->sum('mentor_amount_kobo');
    }

    /**
     * The client's own details, revealed to the mentor at the same moment the
     * mentor's are revealed to the client. The exchange is symmetric on
     * purpose: neither side is asked to give up more than the other.
     *
     * @return array<string, string|null>|null
     */
    public function clientContact(): ?array
    {
        if (! $this->revealsContact()) {
            return null;
        }

        $client = $this->client;

        return [
            'name' => $client?->displayName(),
            'phone' => $client?->profile?->phone,
            'email' => $client?->email,
            'state' => $client?->profile?->state,
            'lga' => $client?->profile?->lga,
        ];
    }

    public function scopeOwnedBy(Builder $query, User|int|null $user): Builder
    {
        // Fails closed, like every other ownership scope here.
        return $query->where(
            'client_id',
            $user instanceof User ? $user->getKey() : ($user ?? 0),
        );
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            EngagementStatus::Active,
            EngagementStatus::AwaitingConfirmation,
            EngagementStatus::Disputed,
        ]);
    }
}
