<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Fields shared by every kind of user, whatever roles they hold.
 */
#[Fillable([
    'display_name',
    'phone',
    'whatsapp',
    'avatar',
    'state',
    'lga',
    'bio',
])]
class Profile extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function avatarUrl(): ?string
    {
        if (blank($this->avatar)) {
            return null;
        }

        return Storage::disk(config('filesystems.default'))->url($this->avatar);
    }
}
