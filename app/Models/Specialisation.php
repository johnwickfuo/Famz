<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A thing a mentor is good at, as a tag rather than a sentence.
 *
 * Matching runs on these and nothing else. The mentor's own description of
 * their strengths is what a client reads; this is what a client is matched by,
 * and keeping the two apart is what stops the shortlist depending on how well
 * somebody writes.
 */
#[Fillable(['name', 'sector', 'description', 'keywords', 'sort_order', 'is_active'])]
class Specialisation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $specialisation): void {
            $specialisation->slug ??= static::uniqueSlug($specialisation->name);
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'specialisation';
        $slug = $base;
        $suffix = 1;

        while (static::query()
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
     * @return BelongsToMany<MentorProfile, $this>
     */
    public function mentors(): BelongsToMany
    {
        return $this->belongsToMany(MentorProfile::class);
    }

    /**
     * Everything a keyword match should look at: the name, the slug's words,
     * and whatever the taxonomy author listed.
     *
     * @return array<int, string>
     */
    public function searchTerms(): array
    {
        return collect([$this->name, str_replace('-', ' ', $this->slug), ...($this->keywords ?? [])])
            ->map(fn (string $term): string => mb_strtolower(trim($term)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sector')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return array<string, string>
     */
    public static function sectorOptions(): array
    {
        return static::query()
            ->active()
            ->distinct()
            ->orderBy('sector')
            ->pluck('sector', 'sector')
            ->map(fn (string $sector): string => Str::headline($sector))
            ->all();
    }
}
