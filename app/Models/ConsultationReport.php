<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * The write-up: what the client actually paid for.
 *
 * Draft until `published_at` is set, and nothing outside the company reads a
 * draft. An administrator writing a report over two days must not have half of
 * it appear in somebody's dashboard, and a client who sees "findings: " with
 * nothing after it has been told something untrue about the work.
 */
#[Fillable(['title', 'findings', 'recommendations', 'follow_up_actions', 'attachments'])]
class ConsultationReport extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'published_at' => 'datetime',
        ];
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
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
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

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }
}
