<?php

namespace App\Services\Catalogue;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;

/**
 * Decides what happens when a seller submits a listing, and when an
 * administrator rules on one.
 *
 * The platform rule: a new seller's listings queue for review. Once three of
 * their listings have been approved, the rest go live straight away — long
 * enough to show they understand what a listing should look like, short enough
 * not to punish a real trader. An administrator can override it per seller in
 * either direction.
 */
class ProductPublisher
{
    /**
     * Move a listing out of draft. Returns the status it landed in.
     */
    public function submit(Product $product): ProductStatus
    {
        $seller = $product->seller;

        $status = $seller->statusForNewListing();

        // A listing with nothing in stock is honest about it rather than
        // going live and disappointing the first buyer.
        if ($status === ProductStatus::Active && $product->stock_quantity < 1) {
            $status = ProductStatus::OutOfStock;
        }

        $product->forceFill([
            'status' => $status,
            'review_notes' => null,
            'published_at' => $status->isPubliclyVisible() ? ($product->published_at ?? now()) : null,
        ])->save();

        return $status;
    }

    public function approve(Product $product, ?User $reviewer = null): Product
    {
        $status = $product->stock_quantity > 0 ? ProductStatus::Active : ProductStatus::OutOfStock;

        $product->forceFill([
            'status' => $status,
            'review_notes' => null,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer?->getKey(),
            'published_at' => $product->published_at ?? now(),
        ])->save();

        return $product;
    }

    public function reject(Product $product, string $reason, ?User $reviewer = null): Product
    {
        $product->forceFill([
            'status' => ProductStatus::Rejected,
            'review_notes' => $reason,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer?->getKey(),
            'published_at' => null,
        ])->save();

        return $product;
    }

    /**
     * How many more approved listings this seller needs before their listings
     * stop queueing. Null when the rule does not apply to them.
     */
    public function listingsUntilAutoApproval(SellerProfile $seller): ?int
    {
        if ($seller->auto_approve_products !== null) {
            return null;
        }

        return max(0, SellerProfile::AUTO_APPROVE_THRESHOLD - $seller->approvedProductCount());
    }
}
