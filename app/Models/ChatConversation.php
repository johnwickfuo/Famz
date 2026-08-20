<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A thread, which outlives the tab it was started in.
 *
 * Guests get one, keyed by a token in their session. That is not a concession —
 * it is the normal case. Most people asking a first question have not
 * registered, and a great many of them never will; an assistant that only
 * remembered logged-in users would forget the conversation halfway through for
 * the majority of the people using it.
 *
 * When a guest does register, the thread comes with them. Losing it at exactly
 * the moment somebody signs up would be the platform punishing them for it.
 */
#[Fillable(['user_id', 'session_token', 'title', 'last_message_at'])]
class ChatConversation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
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
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    public function belongsToGuest(): bool
    {
        return $this->user_id === null;
    }

    /**
     * The title, derived from the first question if nobody set one.
     */
    public function displayTitle(): string
    {
        if (filled($this->title)) {
            return (string) $this->title;
        }

        $first = $this->messages()->where('role', 'user')->value('content');

        return filled($first) ? Str::limit((string) $first, 60) : __('New conversation');
    }

    public static function newSessionToken(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOwnedBy(Builder $query, ?User $user, ?string $sessionToken): void
    {
        /*
         * A signed-in user's threads are theirs by user_id and nothing else. A
         * guest's are theirs by token. The two are never OR-ed together: doing
         * that would mean a stale token in somebody's session could read a
         * conversation that had since been claimed by an account.
         */
        if ($user !== null) {
            $query->where('user_id', $user->getKey());

            return;
        }

        $query->whereNull('user_id')->where('session_token', (string) $sessionToken);

        // A blank token must match nothing rather than every guest thread.
        if (blank($sessionToken)) {
            $query->whereRaw('1 = 0');
        }
    }
}
