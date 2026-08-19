<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a client thought.
 *
 * Nothing here is visible to anybody but its author, the mentor and an
 * administrator until it is approved, and nothing counts toward a rating until
 * then either. That is a deliberate trade: it costs the platform immediacy and
 * buys it the ability to take down a review written in a temper — on a market
 * where one bad rating can end a small mentor's practice.
 */
class MentorReview extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            $review->status ??= ReviewStatus::Pending;
        });

        // Approving, rejecting or deleting one changes what the mentor's
        // rating should be, so the rating is recomputed from the rows rather
        // than nudged.
        static::saved(fn (self $review) => $review->mentor?->refreshStandings());
        static::deleted(fn (self $review) => $review->mentor?->refreshStandings());
    }

    /**
     * @return BelongsTo<MentorshipEngagement, $this>
     */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(MentorshipEngagement::class, 'mentorship_engagement_id');
    }

    /**
     * @return BelongsTo<MentorProfile, $this>
     */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(MentorProfile::class, 'mentor_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }

    /**
     * @return array<string, mixed>
     */
    public function card(): array
    {
        return [
            'rating' => $this->rating,
            'comment' => $this->comment,
            // First name and last initial: enough for the review to read as a
            // person's rather than a robot's, without publishing a client list.
            'by' => $this->shortAuthor(),
            'at' => $this->created_at?->format('j M Y'),
        ];
    }

    private function shortAuthor(): string
    {
        $name = trim((string) ($this->client?->displayName() ?? ''));

        if ($name === '') {
            return __('A client');
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];

        return count($parts) === 1
            ? $parts[0]
            : $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Approved);
    }

    public function scopeAwaitingModeration(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Pending);
    }
}
