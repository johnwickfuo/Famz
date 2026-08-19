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
use LogicException;

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
            'reserved_quantity' => 'integer',
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

            /*
             * Live birds and perishable goods are the two things on this
             * platform that go wrong between the sale and the buyer, so a
             * listing for either must say how it reaches them.
             *
             * Enforced on the model rather than only in the seller's form: an
             * admin edit, an import or a seeder must not be able to create one
             * without it either. A draft is exempt — a seller is allowed to
             * save half a thought and come back to it — but nothing publicly
             * visible is.
             */
            if ($product->status->isPubliclyVisible()
                && $product->needsHandlingNote()
                && ! $product->hasHandlingNote()
            ) {
                throw new LogicException(
                    'A live animal or perishable listing must carry a handling note before it can be published.'
                );
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
        // Columns are qualified because these scopes are used alongside joins
        // — the state filter joins seller_profiles, which has its own `status`.
        return $query
            ->whereIn('products.status', [ProductStatus::Active, ProductStatus::OutOfStock])
            ->whereHas('seller', fn (Builder $seller) => $seller->approved());
    }

    public function scopeBuyable(Builder $query): Builder
    {
        return $query
            ->where('products.status', ProductStatus::Active)
            ->where('products.stock_quantity', '>', 0);
    }

    /**
     * Restrict to one seller. Paired with a policy — see ProductPolicy — so a
     * missing scope alone can never leak another seller's records.
     */
    public function scopeOwnedBy(Builder $query, ?SellerProfile $seller): Builder
    {
        // A null seller matches nothing rather than everything: failing closed
        // is the only safe reading of "no seller".
        return $query->where('products.seller_id', $seller?->getKey() ?? 0);
    }

    public function scopeInCategoryTree(Builder $query, Category $category): Builder
    {
        return $query->whereIn('products.category_id', $category->descendantIds());
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

        $words = preg_split('/\s+/', $terms, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $expression = self::booleanModeExpression($words);

            // Every word was punctuation or too short for the full-text index
            // to have tokenised. Falling through to LIKE finds them anyway
            // rather than returning a confidently empty page.
            if ($expression !== '') {
                return $query->whereFullText(['products.name', 'products.description'], $expression, ['mode' => 'boolean']);
            }
        }

        return $query->where(function (Builder $outer) use ($words): void {
            foreach ($words as $word) {
                $like = '%'.addcslashes($word, '%_\\').'%';

                $outer->where(fn (Builder $inner) => $inner
                    ->where('products.name', 'like', $like)
                    ->orWhere('products.description', 'like', $like));
            }
        });
    }

    /**
     * Build a boolean-mode expression from what a person typed.
     *
     * Two things matter here. Boolean-mode operators (+ - * " ~ < > ( )) are
     * stripped, because a stray hyphen in "day-old" would otherwise mean
     * "exclude old" and quietly return the wrong thing. And each word gets a
     * trailing wildcard, because somebody searching "feed" means feeds, feeder
     * and feeding — which a bare token match would miss entirely.
     *
     * @param  array<int, string>  $words
     */
    private static function booleanModeExpression(array $words): string
    {
        // Words shorter than the index's token size are never matched by
        // full-text, so including them would silently exclude every row.
        $minimum = 3;

        return collect($words)
            ->map(fn (string $word): string => preg_replace('/[+\-*~<>()"@]+/u', ' ', $word) ?? '')
            ->flatMap(fn (string $word): array => preg_split('/\s+/', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= $minimum)
            ->map(fn (string $word): string => '+'.$word.'*')
            ->implode(' ');
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
     * What one unit costs at a given quantity, for a given option.
     *
     * A bulk tier replaces the *base* price, and the option's difference still
     * applies on top: at ten bags, a 50kg bag must still cost more than a 25kg
     * bag. Letting the tier win outright would quietly sell the larger option
     * at the smaller one's price.
     */
    public function unitPriceKoboFor(int $quantity, ?ProductVariant $variant = null): int
    {
        $tier = $this->priceTiers
            ->filter(fn (ProductPriceTier $tier): bool => $quantity >= $tier->min_quantity)
            ->sortByDesc('min_quantity')
            ->first();

        $base = $tier?->unit_price_kobo ?? $this->price_kobo;

        return max(0, $base + ($variant?->price_delta_kobo ?? 0));
    }

    /**
     * What anybody else can actually buy.
     *
     * Stock held back for a buyer whose negotiated price was accepted is not
     * on sale — it is theirs until their window closes. Selling the same bag
     * twice is the failure this exists to prevent.
     */
    public function availableStock(): int
    {
        return max(0, $this->stock_quantity - (int) $this->reserved_quantity);
    }

    public function isInStock(): bool
    {
        return $this->availableStock() > 0;
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
