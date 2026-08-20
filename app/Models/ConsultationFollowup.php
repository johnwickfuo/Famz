<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One message in the conversation after the report.
 *
 * `sender` is stored rather than worked out from the author's roles: an
 * administrator can also be somebody's client on another consultation, and a
 * thread that decides who was speaking by looking up roles today would rewrite
 * its own history the moment somebody is promoted.
 */
class ConsultationFollowup extends Model
{
    use HasFactory;

    public const FROM_CLIENT = 'client';

    public const FROM_COMPANY = 'company';

    protected function casts(): array
    {
        return ['attachments' => 'array', 'is_internal' => 'boolean'];
    }

    /**
     * @return BelongsTo<Consultation, $this>
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isFromCompany(): bool
    {
        return $this->sender === self::FROM_COMPANY;
    }

    /**
     * @return array<int, string>
     */
    public function attachmentUrls(): array
    {
        return collect($this->attachments ?? [])
            ->map(fn (string $path): string => Storage::disk('public')->url($path))
            ->all();
    }

    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }
}
