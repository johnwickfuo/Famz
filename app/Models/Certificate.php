<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An issued certificate.
 *
 * Every field the PDF needs is stored here rather than looked up when it
 * renders. That is the whole point: a student who downloads their certificate
 * again in two years gets the document they were given, not one bearing
 * whatever the company has since renamed itself — and the verification page can
 * say what it said on the day.
 */
class Certificate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quiz_score_percent' => 'integer',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $certificate): void {
            $certificate->verification_code ??= static::newCode();
        });
    }

    /**
     * A code somebody can read off a printed page over a bad phone line.
     *
     * No vowels, so it cannot spell anything unfortunate, and none of the
     * characters that get confused when read aloud or retyped.
     */
    public static function newCode(): string
    {
        $alphabet = '23456789BCDFGHJKMNPQRSTVWXYZ';

        do {
            $code = collect(range(1, 12))
                ->map(fn (): string => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->chunk(4)
                ->map(fn ($chunk): string => $chunk->implode(''))
                ->implode('-');
        } while (static::query()->where('verification_code', $code)->exists());

        return $code;
    }

    public function getRouteKeyName(): string
    {
        return 'verification_code';
    }

    /**
     * @return BelongsTo<Enrolment, $this>
     */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null;
    }

    public function filename(): string
    {
        return Str::slug($this->course_title.' certificate '.$this->verification_code).'.pdf';
    }
}
