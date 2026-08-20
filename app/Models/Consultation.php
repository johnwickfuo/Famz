<?php

namespace App\Models;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Somebody asking the company for help.
 *
 * The client does not choose a person — they ask the company and the company
 * decides who deals with it. That is why there is no professional on this
 * record and no availability to book against.
 */
class Consultation extends Model
{
    use HasFactory, RecordsActivity;

    protected function casts(): array
    {
        return [
            'tier' => ConsultationTier::class,
            'status' => ConsultationStatus::class,
            'attachments' => 'array',
            'flock_size' => 'integer',
            'quoted_amount_kobo' => 'integer',
            'quoted_at' => 'datetime',
            'paid_at' => 'datetime',
            'response_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $consultation): void {
            $consultation->reference ??= static::newReference();
            $consultation->status ??= ConsultationStatus::Submitted;
            $consultation->tier ??= ConsultationTier::Standard;
        });
    }

    public static function newReference(): string
    {
        do {
            $reference = 'CON-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function quoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quoted_by');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<ConsultationReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(ConsultationReport::class)->latest('id');
    }

    /**
     * The write-up the client can actually see.
     *
     * A draft is not a report as far as anybody outside the company is
     * concerned, and this relation is what every client-facing page reads.
     *
     * @return HasOne<ConsultationReport, $this>
     */
    public function publishedReport(): HasOne
    {
        return $this->hasOne(ConsultationReport::class)
            ->whereNotNull('published_at')
            ->latest('published_at');
    }

    /**
     * @return HasMany<ConsultationFollowup, $this>
     */
    public function followups(): HasMany
    {
        return $this->hasMany(ConsultationFollowup::class)->oldest();
    }

    // -----------------------------------------------------------------------
    // The promise
    // -----------------------------------------------------------------------

    /**
     * Whether the company has yet to make contact after the deadline.
     *
     * Only ever true while the consultation is still waiting to be answered:
     * once somebody has rung, the promise was kept, and a slow week afterwards
     * is a different problem from a broken promise.
     */
    public function isOverdue(?Carbon $at = null): bool
    {
        if (! $this->status->awaitsFirstResponse() || $this->response_due_at === null) {
            return false;
        }

        return $this->response_due_at->isBefore($at ?? now());
    }

    /**
     * Coming up, but not late yet.
     */
    public function isDueSoon(int $withinHours = 6, ?Carbon $at = null): bool
    {
        if (! $this->status->awaitsFirstResponse() || $this->response_due_at === null) {
            return false;
        }

        $at ??= now();

        return $this->response_due_at->isAfter($at)
            && $this->response_due_at->isBefore($at->copy()->addHours($withinHours));
    }

    /**
     * How late, or how long left, in words.
     *
     * Two parts because the default single part floors: a deadline seven hours
     * and fifty minutes away reads as "7 hours from now", which quietly shaves
     * an hour off a promise somebody is waiting on.
     */
    public function responseCountdown(): ?string
    {
        if ($this->first_responded_at !== null) {
            return null;
        }

        return $this->response_due_at?->diffForHumans(parts: 2);
    }

    /**
     * How long the company actually took, once it has answered.
     */
    public function responseTaken(): ?string
    {
        if ($this->first_responded_at === null) {
            return null;
        }

        return $this->created_at?->diffForHumans($this->first_responded_at, syntax: true, parts: 2);
    }

    // -----------------------------------------------------------------------

    public function quotedAmount(): ?string
    {
        return $this->quoted_amount_kobo === null ? null : Money::fromKobo($this->quoted_amount_kobo);
    }

    public function isQuoted(): bool
    {
        return $this->quoted_amount_kobo !== null && $this->quoted_at !== null;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /**
     * Whether the client still owes for this.
     */
    public function awaitsPayment(): bool
    {
        return $this->isQuoted() && ! $this->isPaid() && ! $this->status->isFinished();
    }

    /**
     * Whether the follow-up thread is still open.
     *
     * Open from the moment it is paid for, and for a configurable stretch after
     * it finishes — a consultation you cannot come back to when the advice
     * meets the farm is worth less than one you can.
     */
    public function followupsOpen(?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->status === ConsultationStatus::Cancelled) {
            return false;
        }

        if ($this->completed_at === null) {
            return $this->isPaid() || $this->status->isLive();
        }

        $days = max(0, (int) settings('consultation_followup_days', 30));

        return $this->completed_at->copy()->addDays($days)->isAfter($at);
    }

    public function followupsCloseAt(): ?Carbon
    {
        if ($this->completed_at === null) {
            return null;
        }

        return $this->completed_at->copy()->addDays(max(0, (int) settings('consultation_followup_days', 30)));
    }

    /**
     * @return array<int, string>
     */
    public function attachmentUrls(): array
    {
        return collect($this->attachments ?? [])
            ->map(fn (string $path): string => Storage::disk('public')->url($path))
            ->all();
    }

    /**
     * Whether this person may see it.
     *
     * A guest booking belongs to whoever holds the reference until somebody
     * registers with that email and claims it.
     */
    public function belongsToUser(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->user_id !== null) {
            return $this->user_id === $user->getKey();
        }

        return mb_strtolower(trim($this->email)) === mb_strtolower(trim((string) $user->email));
    }

    public function scopeOwnedBy(Builder $query, User|int|null $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * The queue: waiting on a call, soonest deadline first.
     */
    public function scopeAwaitingResponse(Builder $query): Builder
    {
        return $query->where('status', ConsultationStatus::Submitted)->orderBy('response_due_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', ConsultationStatus::Submitted)
            ->whereNotNull('response_due_at')
            ->where('response_due_at', '<', now());
    }

    /**
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['status', 'quoted_amount_kobo', 'quoted_by'];
    }
}
