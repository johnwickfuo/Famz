<?php

namespace App\Models;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the ledger.
 *
 * Amounts are signed kobo. There is no balance column on this model or any
 * other: balances are summed from these rows every time they are asked for, so
 * they cannot drift from the entries that produced them.
 *
 * A null user_id means the platform's own account, which is where commission is
 * recorded. It is deliberately not a real user row — the platform's books
 * should not be an account somebody could sign in to.
 */
class WalletTransaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'state' => LedgerState::class,
            'amount_kobo' => 'integer',
            'meta' => 'array',
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
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The platform's own entries.
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    public function scopeForUser(Builder $query, User|int|null $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $id === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', $id);
    }

    public function scopeSpendable(Builder $query): Builder
    {
        return $query->whereIn('state', LedgerState::spendable());
    }

    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('state', LedgerState::Held);
    }

    public function isPlatform(): bool
    {
        return $this->user_id === null;
    }
}
