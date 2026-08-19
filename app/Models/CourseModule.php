<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['course_id', 'title', 'summary', 'sort_order'])]
class CourseModule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // The database cascade would take the lesson rows without ever loading
        // them, so their model events never fire and their uploaded files stay
        // on the private disk forever. Deleting them through Eloquent first
        // costs one query and keeps the disk honest.
        static::deleting(function (self $module): void {
            $module->lessons()->cursor()->each(fn (CourseLesson $lesson) => $lesson->delete());
        });
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<CourseLesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(CourseLesson::class)->orderBy('sort_order')->orderBy('id');
    }
}
