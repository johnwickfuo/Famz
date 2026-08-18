<?php

namespace App\Models;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\UnitOfMeasure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'category_id',
    'name',
    'description',
    'condition',
    'unit_of_measure',
    'price_kobo',
    'compare_at_price_kobo',
    'stock_quantity',
    'min_order_quantity',
    'is_negotiable',
    'requires_delivery_quote',
    'is_perishable',
    'is_live_animal',
    'handling_note',
])]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'condition' => ProductCondition::class,
            'unit_of_measure' => UnitOfMeasure::class,
            'status' => ProductStatus::class,
            'price_kobo' => 'integer',
            'compare_at_price_kobo' => 'integer',
            'stock_quantity' => 'integer',
            'min_order_quantity' => 'integer',
            'views_count' => 'integer',
            'is_negotiable' => 'boolean',
            'requires_delivery_quote' => 'boolean',
            'is_perishable' => 'boolean',
            'is_live_animal' => 'boolean',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => ProductStatus::Draft->value,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            if (blank($product->slug)) {
                $product->slug = static::uniqueSlug($product->name);
            }

            // A listing with no stock says so itself rather than waiting for a
            // buyer to find out at checkout. Sellers who are mid-restock keep
            // their drafts and rejections untouched.
            if ($product->status === ProductStatus::Active && $product->stock_quantity < 1) {
                $product->status = ProductStatus::OutOfStock;
            } elseif ($product->status === ProductStatus::OutOfStock && $product->stock_quantity > 0) {
                $product->status = ProductStatus::Active;
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'listing';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
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
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ProductPriceTier, $this>
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_quantity');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Everything a shopper is allowed to see. Out-of-stock listings stay
     * visible: a farmer wants to know a seller carries the item at all.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [ProductStatus::Active, ProductStatus::OutOfStock])
            ->whereHas('seller', fn (Builder $seller) => $seller->approved());
    }

    public function scopeBuyable(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active)->where('stock_quantity', '>', 0);
    }

    /**
     * Restrict to one seller. Paired with a policy — see ProductPolicy — so a
     * missing scope alone can never leak another seller's records.
     */
    public function scopeOwnedBy(Builder $query, ?SellerProfile $seller): Builder
    {
        // A null seller matches nothing rather than everything: failing closed
        // is the only safe reading of "no seller".
        return $query->where('seller_id', $seller?->getKey() ?? 0);
    }

    public function scopeInCategoryTree(Builder $query, Category $category): Builder
    {
        return $query->whereIn('category_id', $category->descendantIds());
    }

    /**
     * Full-text search over name and description.
     *
     * MySQL gets a real FULLTEXT match in boolean mode, which is what the
     * index in the migration is for. SQLite has no equivalent, so the test
     * suite falls back to a LIKE scan — correct, just not indexed.
     */
    public function scopeSearch(Builder $query, ?string $terms): Builder
    {
        $terms = trim((string) $terms);

        if ($terms === '') {
            return $query;
        }

        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $query->whereFullText(['name', 'description'], $terms, ['mode' => 'boolean']);
        }

        $words = preg_split('/\s+/', $terms, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $query->where(function (Builder $outer) use ($words): void {
            foreach ($words as $word) {
                $like = '%'.addcslashes($word, '%_\\').'%';

                $outer->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like));
            }
        });
    }

    // ---------------------------------------------------------------------
    // Money and stock
    // ---------------------------------------------------------------------

    public function priceNaira(): float
    {
        return $this->price_kobo / 100;
    }

    public function compareAtPriceNaira(): ?float
    {
        return $this->compare_at_price_kobo === null ? null : $this->compare_at_price_kobo / 100;
    }

    public function hasDiscount(): bool
    {
        return $this->compare_at_price_kobo !== null
            && $this->compare_at_price_kobo > $this->price_kobo;
    }

    public function discountPercent(): ?int
    {
        if (! $this->hasDiscount()) {
            return null;
        }

        return (int) round((1 - $this->price_kobo / $this->compare_at_price_kobo) * 100);
    }

    /**
     * The unit price at a given quantity, honouring bulk tiers. Falls back to
     * the list price when no tier applies.
     */
    public function unitPriceKoboFor(int $quantity): int
    {
        $tier = $this->priceTiers
            ->filter(fn (ProductPriceTier $tier): bool => $quantity >= $tier->min_quantity)
            ->sortByDesc('min_quantity')
            ->first();

        return $tier?->unit_price_kobo ?? $this->price_kobo;
    }

    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    /**
     * Live birds and perishable goods have to say how they will be handled or
     * delivered. Enforced in the seller form, the admin form and the model
     * factory alike, so no path can create one without it.
     */
    public function needsHandlingNote(): bool
    {
        return $this->is_live_animal || $this->is_perishable;
    }

    public function hasHandlingNote(): bool
    {
        return filled($this->handling_note);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
