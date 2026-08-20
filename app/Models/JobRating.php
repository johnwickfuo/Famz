<?php

namespace App\Models;

use App\Enums\RatingParty;
use App\Enums\RatingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one side said about the other after a hire.
 *
 * Nothing here is visible until an administrator approves it. That costs
 * immediacy and buys the ability to take down something written in a temper —
 * on a board where a bad word can cost somebody a season's work, that is a
 * trade worth making.
 */
#[Fillable(['rating', 'comment'])]
class JobRating extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rated_by' => RatingParty::class,
            'status' => RatingStatus::class,
            'rating' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $rating): void {
            $rating->status ??= RatingStatus::Pending;
        });
    }

    /**
     * @return BelongsTo<JobApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function isPublished(): bool
    {
        return $this->status->isPublished();
    }

    /**
     * Who this rating is about.
     */
    public function subject(): RatingParty
    {
        return $this->rated_by->subject();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', RatingStatus::Approved);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAbout(Builder $query, RatingParty $party): void
    {
        // A rating ABOUT a worker was written BY the employer.
        $query->where('rated_by', $party->subject());
    }
}
