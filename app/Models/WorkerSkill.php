<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Something somebody can do on a farm, as a tag.
 *
 * Matching runs on these and nothing else. A worker's own description of
 * themselves is what an employer reads; this is what they are ranked by, and
 * keeping the two apart is what stops a shortlist depending on how well
 * somebody writes.
 */
#[Fillable(['name', 'sector', 'description', 'sort_order', 'is_active'])]
class WorkerSkill extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $skill): void {
            $skill->slug ??= static::uniqueSlug($skill->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<WorkerProfile, $this>
     */
    public function workers(): BelongsToMany
    {
        return $this->belongsToMany(WorkerProfile::class, 'worker_profile_skill');
    }

    /**
     * @return BelongsToMany<JobListing, $this>
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(JobListing::class, 'job_listing_skill');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The picker's shape: sectors in order, skills in order inside them.
     *
     * @return array<int, array{sector: string, skills: array<int, array{id: int, name: string}>}>
     */
    public static function grouped(): array
    {
        return static::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('sector')
            ->map(fn ($skills, string $sector): array => [
                'sector' => $sector,
                'skills' => $skills->map(fn (self $skill): array => [
                    'id' => $skill->id,
                    'name' => $skill->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
