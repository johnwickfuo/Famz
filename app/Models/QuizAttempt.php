<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score_percent' => 'integer',
            'correct_count' => 'integer',
            'question_count' => 'integer',
            'passed' => 'boolean',
            'answers' => 'array',
            'attempted_at' => 'datetime',
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
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
