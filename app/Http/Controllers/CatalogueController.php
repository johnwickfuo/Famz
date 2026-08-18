<?php

namespace App\Http\Controllers;

use App\Enums\ProductCondition;
use App\Http\Resources\ProductCard;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Services\Catalogue\CatalogueQuery;
use App\Services\Catalogue\CategoryCounts;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogueController extends Controller
{
    public function __construct(
        private readonly CatalogueQuery $catalogue,
        private readonly CategoryCounts $counts,
    ) {}

    /**
     * The catalogue home: what to browse, and what has just arrived.
     */
    public function home(): Response
    {
        return Inertia::render('Catalogue/Home', [
            'featuredCategories' => $this->featuredCategories(),
            'newestProducts' => ProductCard::collection(
                Product::query()
                    ->visible()
                    ->with(['images', 'seller', 'category', 'priceTiers'])
                    ->orderByDesc('published_at')
                    ->limit(12)
                    ->get(),
            ),
        ]);
    }

    /**
     * Browsing a category, including everything filed beneath it.
     */
    public function category(Request $request, Category $category): Response
    {
        abort_unless($category->is_active, 404);

        $products = $this->catalogue->paginate(
            $request,
            Product::query()->inCategoryTree($category),
        );

        return Inertia::render('Catalogue/Category', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
            ],
            'breadcrumbs' => $this->breadcrumbsFor($category),
            'children' => $category->children()
                ->where('is_active', true)
                ->get()
                ->map(fn (Category $child): array => [
                    'name' => $child->name,
                    'slug' => $child->slug,
                    // The whole subtree: products live on leaves, so a direct
                    // count would read zero beside a branch full of stock.
                    'count' => $this->counts->for($child),
                ])->all(),
            'products' => ProductCard::collection($products->items()),
            'pagination' => $this->paginationProps($products),
            'filters' => $this->catalogue->activeFilters($request),
            'filterOptions' => $this->filterOptions($category),
        ]);
    }

    /**
     * Full-text search across every visible listing.
     */
    public function search(Request $request): Response
    {
        $products = $this->catalogue->paginate($request);

        return Inertia::render('Catalogue/Search', [
            'query' => $request->string('q')->toString(),
            'products' => ProductCard::collection($products->items()),
            'pagination' => $this->paginationProps($products),
            'filters' => $this->catalogue->activeFilters($request),
            'filterOptions' => $this->filterOptions(),
        ]);
    }

    /**
     * A single seller's storefront.
     */
    public function storefront(Request $request, SellerProfile $seller): Response
    {
        abort_unless($seller->isApproved(), 404);

        $products = $this->catalogue->paginate(
            $request,
            Product::query()->where('seller_id', $seller->getKey()),
        );

        return Inertia::render('Catalogue/Storefront', [
            'seller' => [
                'business_name' => $seller->business_name,
                'slug' => $seller->slug,
                'description' => $seller->description,
                'location' => $seller->location(),
                'state' => $seller->state,
                'logo_url' => $seller->logoUrl(),
                'member_since' => $seller->reviewed_at?->format('F Y') ?? $seller->created_at->format('F Y'),
                'listing_count' => $seller->products()->visible()->count(),
                // Ratings arrive with orders, in a later phase. Shown as a
                // placeholder rather than invented.
                'rating' => null,
            ],
            'products' => ProductCard::collection($products->items()),
            'pagination' => $this->paginationProps($products),
            'filters' => $this->catalogue->activeFilters($request),
            'filterOptions' => $this->filterOptions(),
        ]);
    }

    /**
     * @return array<int, array{name: string, slug: string, icon: string|null, description: string|null, count: int, children: array<int, array{name: string, slug: string}>}>
     */
    private function featuredCategories(): array
    {
        return Category::query()
            ->active()
            ->roots()
            ->ordered()
            ->with(['children' => fn ($query) => $query->where('is_active', true)->limit(5)])
            ->get()
            ->map(fn (Category $category): array => [
                'name' => $category->name,
                'slug' => $category->slug,
                'icon' => $category->icon,
                'description' => $category->description,
                'count' => $this->counts->for($category),
                'children' => $category->children
                    ->map(fn (Category $child): array => ['name' => $child->name, 'slug' => $child->slug])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{label: string, href: string|null}>
     */
    private function breadcrumbsFor(Category $category): array
    {
        return $category->ancestors()
            ->push($category)
            ->map(fn (Category $node): array => [
                'label' => $node->name,
                'href' => route('catalogue.category', $node->slug),
            ])
            ->all();
    }

    /**
     * @param  LengthAwarePaginator<int, Product>  $products
     * @return array<string, mixed>
     */
    private function paginationProps($products): array
    {
        return [
            'links' => $products->linkCollection()->toArray(),
            'from' => $products->firstItem(),
            'to' => $products->lastItem(),
            'total' => $products->total(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(?Category $category = null): array
    {
        return [
            'states' => $this->catalogue->statesWithStock($category),
            'conditions' => collect(ProductCondition::cases())
                ->map(fn (ProductCondition $condition): array => [
                    'value' => $condition->value,
                    'label' => $condition->label(),
                ])->all(),
            'sorts' => CatalogueQuery::sortOptions(),
            'currency' => Money::SIGN,
        ];
    }
}
