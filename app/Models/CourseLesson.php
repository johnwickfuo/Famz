<?php

namespace App\Models;

use App\Enums\LessonType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One lesson.
 *
 * `file_path` is a path inside the private course-content disk and nothing in
 * this application turns it into a URL. There is no accessor for one, and there
 * must never be: the only route to the bytes is a short-lived signed link that
 * checks the enrolment first.
 */
/*
 * `file_path` and `file_name` are fillable because the admin panel writes them
 * through the lessons repeater; without them an uploaded handout would be
 * stored on disk and then silently dropped on the way to the row. `mime_type`
 * and `file_size` deliberately are not: they are read off the disk in the
 * saving hook, so they cannot disagree with the file they describe.
 */
#[Fillable([
    'course_module_id', 'title', 'type', 'content', 'file_path', 'file_name',
    'duration_seconds', 'is_preview', 'sort_order',
])]
class CourseLesson extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'is_preview' => 'boolean',
            'duration_seconds' => 'integer',
            'file_size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $lesson): void {
            // The file's own facts, read off the disk rather than taken from
            // whatever form posted them. True whether the upload came from the
            // admin panel, a seeder or the console.
            if ($lesson->isDirty('file_path')) {
                $lesson->readFileFacts();
            }
        });

        static::updating(function (self $lesson): void {
            // Replacing a file leaves the old one on the private disk forever
            // otherwise: nothing else ever looks at that path again.
            $previous = $lesson->getOriginal('file_path');

            if ($lesson->isDirty('file_path') && filled($previous)) {
                self::forget($previous);
            }
        });

        static::deleted(function (self $lesson): void {
            self::forget($lesson->file_path);
        });
    }

    /**
     * Fill in what the disk says about the file now on this lesson.
     */
    public function readFileFacts(): void
    {
        if (blank($this->file_path)) {
            $this->file_name = null;
            $this->mime_type = null;
            $this->file_size = null;

            return;
        }

        $disk = Storage::disk('course-content');

        try {
            $this->mime_type = $disk->mimeType($this->file_path) ?: null;
            $this->file_size = $disk->size($this->file_path);
        } catch (Throwable) {
            // A path with no file behind it yet — a seeder writing rows before
            // the bytes, say. The serving controller checks existence anyway.
            $this->mime_type ??= null;
            $this->file_size ??= null;
        }

        // Derive a name unless this same save supplied a real one. An
        // upload carries the original filename and that is worth keeping; a
        // path swapped in from a seeder must not inherit the last file's name.
        if (blank($this->file_name) || ! $this->isDirty('file_name')) {
            $this->file_name = basename($this->file_path);
        }
    }

    private static function forget(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        try {
            Storage::disk('course-content')->delete($path);
        } catch (Throwable) {
            // Already gone, or the disk is unreachable. Neither is worth
            // failing a save that has otherwise succeeded.
        }
    }

    /**
     * @return BelongsTo<CourseModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    /**
     * @return HasMany<LessonProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'course_lesson_id');
    }

    public function course(): ?Course
    {
        return $this->module?->course;
    }

    public function hasFile(): bool
    {
        return $this->type->hasFile() && filled($this->file_path);
    }

    /**
     * How long it runs, for a curriculum list.
     */
    public function durationLabel(): ?string
    {
        if ($this->duration_seconds === null || $this->duration_seconds < 1) {
            return null;
        }

        $minutes = (int) floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return $minutes > 0
            ? sprintf('%d:%02d', $minutes, $seconds)
            : sprintf('0:%02d', $seconds);
    }

    public function scopePreview(Builder $query): Builder
    {
        return $query->where('is_preview', true);
    }
}
