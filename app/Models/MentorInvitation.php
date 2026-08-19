<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The only way to become a mentor.
 *
 * There is no public signup form and no link in any navigation. An
 * administrator creates one of these, copies the signed URL and sends it to
 * somebody they have decided to invite — by WhatsApp, usually, because that is
 * where this conversation actually happens.
 *
 * Two independent protections, deliberately not one:
 *
 *  · the URL is signed, so it cannot be constructed by guessing;
 *  · the token is checked here, so a signed URL for a token that has been used
 *    or has expired still gets nowhere.
 *
 * The second is the one that matters. A signature proves we made the link, not
 * that it is still good.
 */
#[Fillable(['email', 'name', 'note', 'expires_at'])]
class MentorInvitation extends Model
{
    use HasFactory;

    /**
     * How long an invitation lasts unless somebody says otherwise. Long enough
     * to reach somebody who checks WhatsApp weekly, short enough that a
     * forwarded message goes stale.
     */
    public const DEFAULT_DAYS = 14;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->token ??= static::newToken();
            $invitation->expires_at ??= now()->addDays(self::DEFAULT_DAYS);
        });
    }

    public static function newToken(): string
    {
        do {
            // 48 characters of URL-safe randomness. The signature is a second
            // lock, not a substitute for the token being unguessable.
            $token = Str::random(48);
        } while (static::query()->where('token', $token)->exists());

        return $token;
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The one question the registration route asks.
     */
    public function isOpen(): bool
    {
        return ! $this->isUsed() && ! $this->hasExpired();
    }

    /**
     * Why it will not open, in words somebody can act on.
     */
    public function refusalReason(): ?string
    {
        return match (true) {
            $this->isUsed() => __('This invitation has already been used.'),
            $this->hasExpired() => __('This invitation has expired. Ask for a new one.'),
            default => null,
        };
    }

    /**
     * Whether this invitation was addressed to a particular person.
     *
     * An invitation with an email on it may only be redeemed by that address.
     * One without is a blank cheque an administrator chose to write.
     */
    public function isAddressed(): bool
    {
        return filled($this->email);
    }

    public function acceptsEmail(string $email): bool
    {
        return ! $this->isAddressed()
            || mb_strtolower(trim($email)) === mb_strtolower(trim((string) $this->email));
    }

    /**
     * Spend it. Called inside the same transaction that creates the mentor, so
     * two people racing the same link cannot both get through.
     */
    public function redeem(User $user): void
    {
        $this->forceFill(['used_at' => now(), 'used_by' => $user->getKey()])->save();
    }

    public function expiresIn(): ?string
    {
        return $this->expires_at?->diffForHumans();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('used_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeSpent(Builder $query): Builder
    {
        return $query->whereNotNull('used_at');
    }

    public function expiryOrNull(): ?Carbon
    {
        return $this->expires_at;
    }
}
