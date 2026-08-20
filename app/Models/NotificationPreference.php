<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's answer to "tell me about this, but not by email".
 *
 * Only written when somebody changes something. The absence of a row is not a
 * missing preference, it is the default — which is why every read of this table
 * goes through NotificationPreferences rather than querying it directly.
 */
#[Fillable(['user_id', 'category', 'database', 'email'])]
class NotificationPreference extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'database' => 'boolean',
            'email' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
