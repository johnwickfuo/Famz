<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The academy's own shelf.
 *
 * Separate from the marketplace tree on purpose: "Poultry" as a thing to buy
 * and "Poultry" as a thing to learn are not the same category, and sharing one
 * would put every new course subject into the shop's navigation.
 */
#[Fillable(['parent_id', 'name', 'description', 'icon', 'sort_order', 'is_active'])]
class CourseCategory extends Model
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
        static::saving(function (self $category): void {
            $category->slug ??= static::uniqueSlug($category->name);
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
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
     * @return BelongsTo<CourseCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<CourseCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * Every category beneath this one, however deep.
     *
     * @return Collection<int, int>
     */
    public function descendantIds(): Collection
    {
        $ids = collect();
        $frontier = collect([$this->getKey()]);

        while ($frontier->isNotEmpty()) {
            $next = static::query()->whereIn('parent_id', $frontier)->pluck('id');
            $ids = $ids->merge($next);
            $frontier = $next;
        }

        return $ids->unique()->values();
    }

    public function pathName(): string
    {
        return $this->parent === null
            ? $this->name
            : $this->parent->pathName().' › '.$this->name;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
