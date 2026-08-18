<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductCard;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function show(Request $request, Product $product): Response
    {
        abort_unless(
            $product->status->isPubliclyVisible() && $product->seller->isApproved(),
            404,
        );

        $product->load(['images', 'variants', 'priceTiers', 'seller', 'category']);

        $this->countView($request, $product);

        return Inertia::render('Catalogue/Product', [
            'product' => $this->productProps($product),
            'breadcrumbs' => $this->breadcrumbs($product),
            'related' => ProductCard::collection(
                Product::query()
                    ->visible()
                    ->where('category_id', $product->category_id)
                    ->whereKeyNot($product->getKey())
                    ->with(['images', 'seller', 'category', 'priceTiers'])
                    ->limit(4)
                    ->get(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productProps(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'condition' => $product->condition->label(),
            'unit' => $product->unit_of_measure->label(),
            'unit_short' => $product->unit_of_measure->shortLabel(),

            'price' => Money::fromKobo($product->price_kobo),
            'price_kobo' => $product->price_kobo,
            'compare_at_price' => $product->compare_at_price_kobo
                ? Money::fromKobo($product->compare_at_price_kobo)
                : null,
            'discount_percent' => $product->discountPercent(),

            'stock_quantity' => $product->stock_quantity,
            'in_stock' => $product->isInStock(),
            'min_order_quantity' => $product->min_order_quantity,
            'is_negotiable' => $product->is_negotiable,
            'requires_delivery_quote' => $product->requires_delivery_quote,

            'is_live_animal' => $product->is_live_animal,
            'is_perishable' => $product->is_perishable,
            'handling_note' => $product->handling_note,

            'images' => $product->images
                ->map(fn ($image): array => ['url' => $image->url(), 'alt' => $image->alt ?? $product->name])
                ->all(),

            'variants' => $product->variants
                ->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'price' => Money::fromKobo($variant->priceKobo()),
                    'price_kobo' => $variant->priceKobo(),
                    'price_delta_kobo' => $variant->price_delta_kobo,
                    'in_stock' => $variant->isInStock(),
                    'stock_quantity' => $variant->stock_quantity,
                ])->all(),

            'price_tiers' => $product->priceTiers
                ->map(fn (ProductPriceTier $tier): array => [
                    'min_quantity' => $tier->min_quantity,
                    'unit_price' => Money::fromKobo($tier->unit_price_kobo),
                    'unit_price_kobo' => $tier->unit_price_kobo,
                    'saving_percent' => $product->price_kobo > 0
                        ? (int) round((1 - $tier->unit_price_kobo / $product->price_kobo) * 100)
                        : null,
                ])->all(),

            'seller' => [
                'business_name' => $product->seller->business_name,
                'slug' => $product->seller->slug,
                'location' => $product->seller->location(),
                'logo_url' => $product->seller->logoUrl(),
                'member_since' => $product->seller->reviewed_at?->format('F Y')
                    ?? $product->seller->created_at->format('F Y'),
                'listing_count' => $product->seller->products()->visible()->count(),
                // Ratings come with orders, in a later phase.
                'rating' => null,
            ],

            'category' => [
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, href: string|null}>
     */
    private function breadcrumbs(Product $product): array
    {
        return collect([['label' => __('Marketplace'), 'href' => route('catalogue.home')]])
            ->merge($product->category->ancestors()->push($product->category)
                ->map(fn ($node): array => [
                    'label' => $node->name,
                    'href' => route('catalogue.category', $node->slug),
                ]))
            ->push(['label' => $product->name, 'href' => null])
            ->all();
    }

    /**
     * Count a view once per visitor per hour, so a refresh does not inflate
     * the number a seller is looking at.
     */
    private function countView(Request $request, Product $product): void
    {
        $key = 'product-view:'.$product->getKey().':'.sha1($request->ip().$request->userAgent());

        if (cache()->add($key, true, now()->addHour())) {
            $product->incrementQuietly('views_count');
        }
    }
}
