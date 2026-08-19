<?php

namespace App\Models;

use App\Enums\QuizQuestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['quiz_id', 'question', 'type', 'explanation', 'sort_order'])]
class QuizQuestion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => QuizQuestionType::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * @return HasMany<QuizOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return Collection<int, int>
     */
    public function correctOptionIds(): Collection
    {
        return $this->options->where('is_correct', true)->pluck('id')->map(fn ($id): int => (int) $id);
    }

    /**
     * Whether a set of chosen option ids answers this question correctly.
     *
     * Exact match, both ways: on a multiple-choice question, missing a right
     * answer is as wrong as adding a wrong one, and half marks for half an
     * answer would let somebody tick everything and pass.
     *
     * @param  array<int, int|string>  $chosen
     */
    public function isAnsweredCorrectlyBy(array $chosen): bool
    {
        $correct = $this->correctOptionIds()->sort()->values();

        $given = collect($chosen)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            // Only options that actually belong to this question count.
            ->intersect($this->options->pluck('id')->map(fn ($id): int => (int) $id))
            ->sort()
            ->values();

        return $correct->isNotEmpty() && $correct->all() === $given->all();
    }
}
