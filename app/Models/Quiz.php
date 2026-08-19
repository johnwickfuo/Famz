<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['course_id', 'title', 'description', 'pass_mark_percent', 'max_attempts', 'is_required_for_certificate', 'is_active'])]
class Quiz extends Model
{
    use HasFactory;

    protected $table = 'quizzes';

    protected function casts(): array
    {
        return [
            'pass_mark_percent' => 'integer',
            'max_attempts' => 'integer',
            'is_required_for_certificate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<QuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Whether this quiz is worth showing at all.
     *
     * A quiz with no questions would pass everybody at 0 out of 0, which is
     * how a certificate gets issued to somebody who answered nothing.
     */
    public function isUsable(): bool
    {
        return $this->is_active && $this->questions()->exists();
    }

    /**
     * How many tries this enrolment has left, or null for unlimited.
     */
    public function attemptsLeftFor(Enrolment $enrolment): ?int
    {
        if ($this->max_attempts === null) {
            return null;
        }

        $used = $this->attempts()->where('enrolment_id', $enrolment->getKey())->count();

        return max(0, $this->max_attempts - $used);
    }

    public function allowsAnotherAttempt(Enrolment $enrolment): bool
    {
        // Somebody who has already passed has nothing left to prove.
        if ($enrolment->hasPassedQuiz()) {
            return false;
        }

        $left = $this->attemptsLeftFor($enrolment);

        return $left === null || $left > 0;
    }
}
