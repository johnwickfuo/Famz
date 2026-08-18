<?php

namespace App\Services\Catalogue;

use App\Enums\ProductCondition;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Turns catalogue query-string parameters into a paginated result.
 *
 * One place, used by browse, search and a seller's storefront alike, so the
 * filters behave identically wherever a shopper meets them.
 */
class CatalogueQuery
{
    public const SORTS = [
        'relevance' => 'Best match',
        'newest' => 'Newest first',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'popular' => 'Most viewed',
    ];

    public const PER_PAGE = 24;

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(Request $request, ?Builder $base = null): LengthAwarePaginator
    {
        $query = ($base ?? Product::query())
            ->visible()
            ->with(['images', 'seller', 'category', 'priceTiers']);

        $terms = trim((string) $request->string('q'));

        if ($terms !== '') {
            $query->search($terms);
        }

        $this->applyFilters($query, $request);
        $this->applySort($query, $request, $terms !== '');

        return $query
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        // Prices arrive in Naira because that is what a person types; the
        // column is kobo.
        if ($request->filled('min_price')) {
            $query->where('price_kobo', '>=', Money::toKobo($request->input('min_price')));
        }

        if ($request->filled('max_price')) {
            $query->where('price_kobo', '<=', Money::toKobo($request->input('max_price')));
        }

        if ($request->filled('state')) {
            $states = (array) $request->input('state');

            $query->whereHas('seller', fn (Builder $seller) => $seller->whereIn('state', $states));
        }

        if ($request->filled('condition')) {
            $conditions = collect((array) $request->input('condition'))
                ->filter(fn ($value): bool => ProductCondition::tryFrom((string) $value) !== null)
                ->values();

            if ($conditions->isNotEmpty()) {
                $query->whereIn('condition', $conditions);
            }
        }

        if ($request->boolean('negotiable')) {
            $query->where('is_negotiable', true);
        }

        if ($request->boolean('in_stock')) {
            $query->buyable();
        }

        // Live animals and perishables carry a handling obligation, so a
        // shopper can also deliberately look for — or avoid — them.
        if ($request->boolean('live_animals')) {
            $query->where('is_live_animal', true);
        }

        if ($request->boolean('perishable')) {
            $query->where('is_perishable', true);
        }
    }

    private function applySort(Builder $query, Request $request, bool $hasSearchTerms): void
    {
        // Relevance means nothing without a search term, so an unsorted browse
        // falls back to what is new.
        $sort = $this->effectiveSort($request);

        match ($sort) {
            'price_asc' => $query->orderBy('price_kobo'),
            'price_desc' => $query->orderByDesc('price_kobo'),
            'popular' => $query->orderByDesc('views_count')->orderByDesc('published_at'),
            // Relevance is the driver's own ordering for a full-text match;
            // recency is the tie-break, and the fallback where there is no
            // full-text index (SQLite).
            'relevance' => $query->orderByDesc('published_at'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * The filter state to hand back to the front end so the controls show what
     * is actually applied.
     *
     * @return array<string, mixed>
     */
    public function activeFilters(Request $request): array
    {
        return [
            'q' => $request->string('q')->toString() ?: null,
            'min_price' => $request->input('min_price'),
            'max_price' => $request->input('max_price'),
            'state' => array_values((array) $request->input('state', [])),
            'condition' => array_values((array) $request->input('condition', [])),
            'negotiable' => $request->boolean('negotiable'),
            'in_stock' => $request->boolean('in_stock'),
            'live_animals' => $request->boolean('live_animals'),
            'perishable' => $request->boolean('perishable'),

            // The sort actually applied, not what was typed: the control should
            // show what the page is doing, and an unset sort is still a sort.
            'sort' => $this->effectiveSort($request),
        ];
    }

    /**
     * The sort in force for this request, resolving a missing or unrecognised
     * value the same way paginate() does.
     */
    public function effectiveSort(Request $request): string
    {
        $sort = (string) $request->string('sort');

        if (array_key_exists($sort, self::SORTS)) {
            return $sort;
        }

        return trim((string) $request->string('q')) !== '' ? 'relevance' : 'newest';
    }

    /**
     * The states that actually have something for sale — a filter listing all
     * 37 when only six have stock is noise.
     *
     * @return array<int, string>
     */
    public function statesWithStock(?Category $category = null): array
    {
        return Product::query()
            ->visible()
            ->when($category, fn (Builder $query) => $query->inCategoryTree($category))
            ->join('seller_profiles', 'products.seller_id', '=', 'seller_profiles.id')
            ->distinct()
            ->orderBy('seller_profiles.state')
            ->pluck('seller_profiles.state')
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function sortOptions(): array
    {
        return collect(self::SORTS)
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => __($label)])
            ->values()
            ->all();
    }
}
