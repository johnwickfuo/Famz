<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A node in the catalogue tree. The tree covers all of agriculture, not just
 * poultry, so that the platform can grow into livestock, crops and services
 * without a schema change.
 */
#[Fillable([
    'parent_id',
    'name',
    'slug',
    'icon',
    'description',
    'sort_order',
    'is_active',
])]
class Category extends Model
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
            if (blank($category->slug)) {
                $category->slug = static::uniqueSlug($category->name);
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return BelongsToMany<SellerProfile, $this>
     */
    public function sellerProfiles(): BelongsToMany
    {
        return $this->belongsToMany(SellerProfile::class);
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

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * This category and every category beneath it, so browsing "Poultry" shows
     * the day-old chicks filed three levels down.
     *
     * @return Collection<int, int>
     */
    public function descendantIds(): Collection
    {
        $ids = collect([$this->getKey()]);
        $frontier = collect([$this->getKey()]);

        // Iterative rather than recursive: the tree is shallow and this keeps
        // it to one query per level instead of one per node.
        while ($frontier->isNotEmpty()) {
            $frontier = static::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id');

            $ids = $ids->merge($frontier);
        }

        return $ids->unique()->values();
    }

    /**
     * Root → … → this category, for breadcrumbs.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $trail = collect();
        $node = $this->parent;

        while ($node !== null) {
            $trail->prepend($node);
            $node = $node->parent;
        }

        return $trail;
    }

    /**
     * "Poultry › Birds › Day-old chicks", for admin selects where the bare name
     * would be ambiguous.
     */
    public function pathName(): string
    {
        return $this->ancestors()
            ->push($this)
            ->pluck('name')
            ->implode(' › ');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
