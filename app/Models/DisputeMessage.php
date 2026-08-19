<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a dispute thread.
 *
 * Buyer, seller and administrator share one thread. Separate threads would let
 * each side tell the arbitrator a different story without the other seeing it,
 * which is the one thing an arbitrator must not have.
 */
#[Fillable(['body', 'attachments', 'is_internal'])]
class DisputeMessage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'is_internal' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Everything the buyer and seller are allowed to see.
     */
    public function scopeVisibleToParties(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }
}
