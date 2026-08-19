<?php

namespace App\Models;

use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A course, written by the company.
 *
 * There is deliberately no instructor relation. Third-party authoring is a
 * different product with different payouts, moderation and liability, and
 * leaving a nullable `instructor_id` here "for later" would be an open door
 * dressed up as foresight.
 */
#[Fillable([
    'course_category_id', 'title', 'summary', 'description', 'promo_video_url',
    'price_kobo', 'currency', 'is_free', 'level', 'estimated_minutes',
    'what_you_will_learn', 'requirements', 'sort_order',
])]
class Course extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'level' => CourseLevel::class,
            'status' => CourseStatus::class,
            'price_kobo' => 'integer',
            'is_free' => 'boolean',
            'estimated_minutes' => 'integer',
            'what_you_will_learn' => 'array',
            'requirements' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $course): void {
            $course->slug ??= static::uniqueSlug($course->title);
            $course->status ??= CourseStatus::Draft;

            // A free course with a price on it is a contradiction somebody will
            // eventually be charged by.
            if ($course->is_free) {
                $course->price_kobo = 0;
            }
        });
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 70, '')) ?: 'course';
        $slug = $base;
        $suffix = 1;

        while (static::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<CourseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
    }

    /**
     * @return HasMany<CourseModule, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasManyThrough<CourseLesson, CourseModule, $this>
     */
    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(CourseLesson::class, CourseModule::class)
            ->orderBy('course_modules.sort_order')
            ->orderBy('course_lessons.sort_order')
            ->orderBy('course_lessons.id');
    }

    /**
     * @return HasMany<Enrolment, $this>
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    /**
     * @return HasOne<Quiz, $this>
     */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function coverUrl(): ?string
    {
        return $this->cover_image === null
            ? null
            : Storage::disk('public')->url($this->cover_image);
    }

    public function price(): string
    {
        return $this->is_free || $this->price_kobo === 0
            ? __('Free')
            : Money::fromKobo($this->price_kobo);
    }

    public function isPurchasable(): bool
    {
        return $this->status === CourseStatus::Published;
    }

    /**
     * Whether somebody may open the player at all.
     *
     * An archived course stays open to the people who already bought it —
     * lifetime access was the promise, and withdrawing it because the company
     * stopped selling the course would be breaking it.
     */
    public function isReadableByEnrolled(): bool
    {
        return $this->status !== CourseStatus::Draft;
    }

    /**
     * How long the whole thing takes, in minutes.
     *
     * The author's own figure wins where they gave one; otherwise it is summed
     * from the lessons, because a course page with no duration on it is a
     * course nobody can plan around.
     */
    public function minutes(): int
    {
        if ($this->estimated_minutes !== null) {
            return $this->estimated_minutes;
        }

        $seconds = (int) $this->lessons()->sum('duration_seconds');

        return (int) ceil($seconds / 60);
    }

    /**
     * Lessons anybody may play without buying.
     *
     * @return Collection<int, CourseLesson>
     */
    public function previewLessons(): Collection
    {
        return $this->lessons()->where('is_preview', true)->get();
    }

    public function publish(): void
    {
        $this->forceFill([
            'status' => CourseStatus::Published,
            'published_at' => $this->published_at ?? now(),
        ])->save();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('published_at');
    }
}
