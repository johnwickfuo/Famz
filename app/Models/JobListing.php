<?php

namespace App\Models;

use App\Enums\JobListingStatus;
use App\Enums\JobType;
use App\Enums\PayPeriod;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A job somebody is advertising.
 *
 * No money passes through the platform for any of this and nothing on this
 * model implies otherwise. The pay figures are what the employer says they will
 * pay, quoted so a worker can decide whether the journey is worth making —
 * they are not an escrow, a guarantee or a promise the company is party to.
 */
#[Fillable([
    'title', 'description', 'job_type', 'positions_available', 'state', 'lga',
    'is_accommodation_provided', 'is_food_provided', 'pay_min_kobo',
    'pay_max_kobo', 'pay_period', 'start_date', 'application_deadline',
])]
class JobListing extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'job_type' => JobType::class,
            'pay_period' => PayPeriod::class,
            'status' => JobListingStatus::class,
            'is_accommodation_provided' => 'boolean',
            'is_food_provided' => 'boolean',
            'positions_available' => 'integer',
            'pay_min_kobo' => 'integer',
            'pay_max_kobo' => 'integer',
            'views_count' => 'integer',
            'start_date' => 'date',
            'application_deadline' => 'date',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $listing): void {
            $listing->reference ??= static::newReference();
            $listing->status ??= JobListingStatus::Draft;
        });

        static::saving(function (self $listing): void {
            $listing->slug ??= static::uniqueSlug($listing->title);
        });
    }

    public static function newReference(): string
    {
        do {
            $reference = 'JOB-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'job';
        $slug = $base.'-'.Str::lower(Str::random(5));

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // -----------------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------------

    /**
     * @return BelongsTo<EmployerProfile, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(EmployerProfile::class, 'employer_profile_id');
    }

    /**
     * @return BelongsToMany<WorkerSkill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(WorkerSkill::class, 'job_listing_skill');
    }

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class)->latest('applied_at');
    }

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status->isOpen() && ! $this->hasLapsed();
    }

    /**
     * Whether the deadline has passed.
     *
     * Checked as well as the status, because the expiry sweep runs on a
     * schedule and a page load can land between a deadline passing and the
     * command noticing. Better to stop taking applications a few hours early
     * than to let somebody apply to a job that closed yesterday.
     */
    public function hasLapsed(?Carbon $at = null): bool
    {
        if ($this->application_deadline === null) {
            return false;
        }

        return $this->application_deadline->endOfDay()->isBefore($at ?? now());
    }

    public function acceptsApplications(): bool
    {
        return $this->isOpen() && $this->status->isPublic();
    }

    public function deadlineCountdown(?Carbon $at = null): ?string
    {
        if ($this->application_deadline === null) {
            return null;
        }

        $at ??= now();

        if ($this->hasLapsed($at)) {
            return __('Closed');
        }

        $days = (int) $at->startOfDay()->diffInDays($this->application_deadline->startOfDay(), absolute: false);

        return match (true) {
            $days <= 0 => __('Last day to apply'),
            $days === 1 => __('1 day left'),
            default => __(':count days left', ['count' => $days]),
        };
    }

    // -----------------------------------------------------------------------
    // Reading it back
    // -----------------------------------------------------------------------

    public function place(): ?string
    {
        return collect([$this->lga, $this->state])->filter()->implode(', ') ?: null;
    }

    public function payRange(): ?string
    {
        $min = $this->pay_min_kobo;
        $max = $this->pay_max_kobo;
        $per = $this->pay_period->label();

        return match (true) {
            $min !== null && $max !== null && $min !== $max => Money::compactFromKobo($min).' – '.Money::compactFromKobo($max).' '.$per,
            $min !== null => Money::compactFromKobo($min).' '.$per,
            $max !== null => __('Up to :amount :per', ['amount' => Money::compactFromKobo($max), 'per' => $per]),
            default => null,
        };
    }

    /**
     * What is thrown in on top of the money.
     *
     * Shown beside the pay rather than buried in the description, because for
     * somebody considering a job three states away these two facts decide
     * whether it is possible at all.
     *
     * @return array<int, string>
     */
    public function perks(): array
    {
        return collect([
            $this->is_accommodation_provided ? __('Accommodation provided') : null,
            $this->is_food_provided ? __('Food provided') : null,
        ])->filter()->values()->all();
    }

    /**
     * Pay normalised to a month, for comparing with a worker's expectation.
     *
     * Rough by design — see PayPeriod::perMonthFactor. Used only to rank.
     *
     * @return array{min: int|null, max: int|null}
     */
    public function monthlyPay(): array
    {
        $factor = $this->pay_period->perMonthFactor();

        return [
            'min' => $this->pay_min_kobo === null ? null : (int) round($this->pay_min_kobo * $factor),
            'max' => $this->pay_max_kobo === null ? null : (int) round($this->pay_max_kobo * $factor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicCard(): array
    {
        return [
            'slug' => $this->slug,
            'reference' => $this->reference,
            'title' => $this->title,
            'job_type' => $this->job_type->label(),
            'job_type_tone' => $this->job_type->badgeTone(),
            'where' => $this->place(),
            'state' => $this->state,
            'pay' => $this->payRange(),
            'perks' => $this->perks(),
            'positions' => $this->positions_available,
            'skills' => $this->skills->pluck('name')->all(),
            'deadline' => $this->application_deadline?->format('j M Y'),
            'deadline_countdown' => $this->deadlineCountdown(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->badgeTone(),
            'is_open' => $this->isOpen(),
            'employer_name' => $this->employer?->business_name,
            'posted' => $this->published_at?->diffForHumans(),
            'url' => route('jobs.show', $this->slug),
        ];
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * Everything the public board shows: open, and not past its deadline.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOnBoard(Builder $query): void
    {
        $query
            ->where('status', JobListingStatus::Open)
            ->where(fn (Builder $q) => $q
                ->whereNull('application_deadline')
                ->orWhereDate('application_deadline', '>=', now()->toDateString()));
    }

    /**
     * Open, dated, and past that date. What the expiry sweep looks for.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLapsed(Builder $query, ?Carbon $at = null): void
    {
        $query
            ->where('status', JobListingStatus::Open)
            ->whereNotNull('application_deadline')
            ->whereDate('application_deadline', '<', ($at ?? now())->toDateString());
    }
}
