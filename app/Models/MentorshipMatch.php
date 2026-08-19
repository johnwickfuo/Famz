<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the matcher, kept.
 *
 * Not for the client — they have already seen the answer — but for whoever has
 * to make the matching better later. Tuning a ranking with no record of what it
 * ranked is guesswork, and the interesting question ("what did we show, and did
 * they hire any of it") can only be asked if both halves were written down.
 *
 * `resolver` says whether the tags came from the AI layer or the keyword
 * fallback, which is how anybody can tell later whether the AI was actually
 * earning its keep.
 */
class MentorshipMatch extends Model
{
    use HasFactory;

    /** The AI layer answered. */
    public const RESOLVER_AI = 'ai';

    /** The AI layer was unavailable, refused, or answered with nothing usable. */
    public const RESOLVER_KEYWORD = 'keyword';

    /** The client picked their own tags and no inference was needed. */
    public const RESOLVER_EXPLICIT = 'explicit';

    protected function casts(): array
    {
        return [
            'matched_specialisation_ids' => 'array',
            'results' => 'array',
            'wants_remote' => 'boolean',
            'wants_in_person' => 'boolean',
            'budget_min_kobo' => 'integer',
            'budget_max_kobo' => 'integer',
            'results_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MentorshipEngagement, $this>
     */
    public function engagements(): HasMany
    {
        return $this->hasMany(MentorshipEngagement::class);
    }

    /**
     * Whether this run got its tags from the AI or fell back.
     */
    public function usedAi(): bool
    {
        return $this->resolver === self::RESOLVER_AI;
    }

    public function resolverLabel(): string
    {
        return match ($this->resolver) {
            self::RESOLVER_AI => __('Matched by AI'),
            self::RESOLVER_EXPLICIT => __('Client chose the tags'),
            default => __('Matched by keyword'),
        };
    }
}
