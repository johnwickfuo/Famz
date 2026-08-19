<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bank account money can be sent to.
 *
 * `account_name` is deliberately not fillable: it comes from the bank through
 * the gateway's resolution endpoint and nowhere else. Letting somebody type
 * their own account name would make `is_verified` a decoration.
 */
#[Fillable(['bank_code', 'bank_name', 'account_number'])]
class PayoutAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
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
     * @return HasMany<Withdrawal, $this>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /**
     * Make this the account payouts go to, and demote whatever was.
     */
    public function makeDefault(): void
    {
        static::query()
            ->where('user_id', $this->user_id)
            ->whereKeyNot($this->getKey())
            ->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
    }

    /**
     * All but the last four digits, for anywhere the number is displayed.
     *
     * A full account number on a shared screen in a market is somebody else's
     * problem waiting to happen.
     */
    public function maskedNumber(): string
    {
        $number = (string) $this->account_number;

        return strlen($number) <= 4
            ? $number
            : str_repeat('•', strlen($number) - 4).substr($number, -4);
    }

    public function label(): string
    {
        return $this->bank_name.' — '.$this->maskedNumber();
    }

    /**
     * Whether this account can actually be paid.
     */
    public function isPayable(): bool
    {
        return $this->is_verified && $this->deleted_at === null;
    }

    /**
     * Fails closed, like every other ownership scope here.
     */
    public function scopeOwnedBy(Builder $query, User|int|null $user): Builder
    {
        return $query->where(
            'payout_accounts.user_id',
            $user instanceof User ? $user->getKey() : ($user ?? 0),
        );
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }
}
