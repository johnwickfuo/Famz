<?php

namespace App\Models;

use App\Enums\JobApplicationStatus;
use App\Enums\RatingParty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody putting themselves forward for a job.
 *
 * The one status that matters beyond reporting is `hired`. It is what unlocks
 * the right to rate, in both directions, and that gate is enforced in
 * JobRatingPolicy rather than in the interface — an ungated rating system on a
 * board where nobody is verified would be abused within a week.
 */
#[Fillable(['cover_message'])]
class JobApplication extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => JobApplicationStatus::class,
            'applied_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $application): void {
            $application->status ??= JobApplicationStatus::Applied;
            $application->applied_at ??= now();
        });
    }

    // -----------------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------------

    /**
     * @return BelongsTo<JobListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_listing_id');
    }

    /**
     * @return BelongsTo<WorkerProfile, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class, 'worker_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function statusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /**
     * @return HasMany<JobRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(JobRating::class);
    }

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------

    public function isHired(): bool
    {
        return $this->status->isHired();
    }

    /**
     * Whether this party has already had their say.
     *
     * One rating per party per application, so neither side can pile on. The
     * unique index enforces it; this is what stops the form being offered.
     */
    public function hasRatingFrom(RatingParty $party): bool
    {
        return $this->ratings()->where('rated_by', $party)->exists();
    }

    /**
     * The employer's account behind this application, if there is one.
     */
    public function employerUser(): ?User
    {
        return $this->listing?->employer?->user;
    }

    public function workerUser(): ?User
    {
        return $this->worker?->user;
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeHired(Builder $query): void
    {
        $query->where('status', JobApplicationStatus::Hired);
    }
}
