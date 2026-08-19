<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Money on its way out of a wallet.
 *
 * A withdrawal reserves against the available balance from the moment it is
 * requested, not from the moment it is paid — otherwise a seller could request
 * the same money five times over while the first request sat waiting for an
 * administrator.
 */
#[Fillable(['payout_account_id', 'amount_kobo'])]
class Withdrawal extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $withdrawal): void {
            $withdrawal->reference ??= static::newReference();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'amount_kobo' => 'integer',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public static function newReference(): string
    {
        do {
            $reference = 'WDR-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
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
     * @return BelongsTo<PayoutAccount, $this>
     */
    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }

    /**
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Requests still holding money out of somebody's balance.
     */
    public function scopeReserving(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (WithdrawalStatus $status): string => $status->value,
            array_filter(
                WithdrawalStatus::cases(),
                fn (WithdrawalStatus $status): bool => $status->reservesFunds(),
            ),
        ));
    }

    public function scopeOwnedBy(Builder $query, User|int|null $user): Builder
    {
        return $query->where(
            'withdrawals.user_id',
            $user instanceof User ? $user->getKey() : ($user ?? 0),
        );
    }

    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', WithdrawalStatus::Requested);
    }
}
