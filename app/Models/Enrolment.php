<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Somebody's right to a course.
 *
 * `enrolled_at` is what grants access, not the row's existence: an enrolment
 * created while a payment is still in flight has no date on it and opens
 * nothing. Everything that guards content asks `isActive()`.
 */
class Enrolment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_paid_kobo' => 'integer',
            'terms_accepted' => 'boolean',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'certificate_issued_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $enrolment): void {
            $enrolment->reference ??= static::newReference();
        });
    }

    public static function newReference(): string
    {
        do {
            $reference = 'ENR-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<LessonProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * @return HasOne<Certificate, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /**
     * @return BelongsTo<CourseLesson, $this>
     */
    public function lastLesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class, 'last_lesson_id');
    }

    /**
     * Whether this enrolment opens anything.
     *
     * A row without `enrolled_at` is a pending purchase, not access — the
     * single check every guard in the academy makes.
     */
    public function isActive(): bool
    {
        return $this->enrolled_at !== null;
    }

    public function pricePaid(): string
    {
        return $this->price_paid_kobo > 0 ? Money::fromKobo($this->price_paid_kobo) : __('Free');
    }

    /**
     * The lessons this enrolment has finished.
     *
     * @return Collection<int, int>
     */
    public function completedLessonIds(): Collection
    {
        return $this->progress()
            ->where('is_completed', true)
            ->pluck('course_lesson_id');
    }

    /**
     * How far through, as a whole percentage.
     *
     * Derived every time from the lessons that exist now: adding a lesson to a
     * course should move everybody's progress bar back, because it truthfully
     * did. A stored percentage would keep saying 100% for a course somebody has
     * not finished any more.
     */
    public function progressPercent(): int
    {
        $total = $this->course->lessons()->count();

        if ($total === 0) {
            return 0;
        }

        $done = $this->progress()
            ->where('is_completed', true)
            ->whereIn('course_lesson_id', $this->course->lessons()->select('course_lessons.id'))
            ->count();

        return (int) floor(($done / $total) * 100);
    }

    public function hasFinishedEveryLesson(): bool
    {
        $total = $this->course->lessons()->count();

        return $total > 0 && $this->progressPercent() >= 100;
    }

    public function bestQuizAttempt(): ?QuizAttempt
    {
        return $this->quizAttempts()->orderByDesc('score_percent')->orderByDesc('id')->first();
    }

    public function hasPassedQuiz(): bool
    {
        return $this->quizAttempts()->where('passed', true)->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('enrolled_at');
    }

    public function scopeOwnedBy(Builder $query, User|int|null $user): Builder
    {
        return $query->where(
            'enrolments.user_id',
            $user instanceof User ? $user->getKey() : ($user ?? 0),
        );
    }
}
