<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address the platform has stopped writing to.
 */
#[Fillable(['email', 'type', 'provider', 'reason', 'payload', 'suppressed_at'])]
class EmailSuppression extends Model
{
    /** The address does not exist, or the mailbox rejected us outright. */
    public const TYPE_BOUNCE = 'bounce';

    /** A working address whose owner pressed "this is spam". */
    public const TYPE_COMPLAINT = 'complaint';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'suppressed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * The user whose address this is, if any.
     *
     * Most suppressed addresses belong to nobody — a consultation booked by
     * somebody who never registered, a quotation client, a contact-form reply.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }
}
