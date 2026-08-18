<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Money;

/**
 * The shape of a product wherever it appears as a card — the catalogue, a
 * category, a storefront, search results.
 *
 * A plain class rather than an API resource because these are Inertia props,
 * and one definition keeps every grid on the site consistent.
 */
class ProductCard
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => Money::fromKobo($product->price_kobo),
            'price_kobo' => $product->price_kobo,
            'compare_at_price' => $product->compare_at_price_kobo
                ? Money::fromKobo($product->compare_at_price_kobo)
                : null,
            'discount_percent' => $product->discountPercent(),
            'unit' => $product->unit_of_measure->shortLabel(),
            'condition' => $product->condition->label(),
            'image' => $product->images->first()?->url(),
            'in_stock' => $product->isInStock(),
            'stock_quantity' => $product->stock_quantity,
            'is_negotiable' => $product->is_negotiable,

            // Surfaced on the card itself, not just the detail page: a farmer
            // scanning a grid needs to know which of these is a living thing
            // before they click.
            'is_live_animal' => $product->is_live_animal,
            'is_perishable' => $product->is_perishable,

            'has_bulk_pricing' => $product->priceTiers->isNotEmpty(),
            'seller' => [
                'name' => $product->seller?->business_name,
                'slug' => $product->seller?->slug,
                'location' => $product->seller?->location(),
            ],
            'category' => [
                'name' => $product->category?->name,
                'slug' => $product->category?->slug,
            ],
        ];
    }

    /**
     * @param  iterable<Product>  $products
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $products): array
    {
        return collect($products)->map(fn (Product $product): array => self::make($product))->values()->all();
    }
}
