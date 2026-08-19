<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    use HasFactory;

    protected $table = 'lesson_progress';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'last_position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrolment, $this>
     */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    /**
     * @return BelongsTo<CourseLesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class, 'course_lesson_id');
    }
}
