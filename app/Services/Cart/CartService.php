<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The cart, wherever it happens to live.
 *
 * Callers never choose a store: a guest gets the session, somebody signed in
 * gets the database, and on login the guest's cart is merged into theirs.
 */
class CartService
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Session $session,
    ) {}

    public function store(?User $user = null): CartStore
    {
        $user ??= $this->auth->guard('web')->user();

        return $user === null
            ? new SessionCartStore($this->session)
            : new DatabaseCartStore($user);
    }

    /**
     * Add to the cart, or increase what is already there.
     *
     * The unit price is resolved now and kept, so the buyer sees a stable
     * figure while they shop. It is checked again at checkout.
     */
    public function add(Product $product, int $quantity = 1, ?ProductVariant $variant = null, ?User $user = null): CartLine
    {
        if (! $product->status->isPubliclyVisible() || ! $product->seller->isApproved()) {
            throw new RuntimeException(__('That listing is not on sale.'));
        }

        if ($variant !== null && $variant->product_id !== $product->getKey()) {
            throw new RuntimeException(__('That option does not belong to this listing.'));
        }

        $store = $this->store($user);
        $existing = $this->find($store, $product->getKey(), $variant?->getKey());

        $quantity = max(1, $quantity) + ($existing?->quantity ?? 0);
        $quantity = $this->clampToStock($product, $variant, $quantity);

        $product->loadMissing('priceTiers');

        $line = new CartLine(
            productId: $product->getKey(),
            variantId: $variant?->getKey(),
            quantity: $quantity,
            unitPriceKobo: $product->unitPriceKoboFor($quantity, $variant),
            product: $product,
            variant: $variant,
        );

        $store->put($line);

        return $line;
    }

    public function updateQuantity(int $productId, ?int $variantId, int $quantity, ?User $user = null): void
    {
        $store = $this->store($user);

        if ($quantity < 1) {
            $store->remove($productId, $variantId);

            return;
        }

        $product = Product::query()->with('priceTiers')->find($productId);

        if ($product === null) {
            $store->remove($productId, $variantId);

            return;
        }

        $variant = $variantId === null ? null : ProductVariant::query()->find($variantId);
        $quantity = $this->clampToStock($product, $variant, $quantity);

        $store->put(new CartLine(
            productId: $productId,
            variantId: $variantId,
            quantity: $quantity,
            // Re-resolved, because bulk tiers depend on the quantity: changing
            // the quantity is exactly when a tier starts or stops applying.
            unitPriceKobo: $product->unitPriceKoboFor($quantity, $variant),
        ));
    }

    public function remove(int $productId, ?int $variantId, ?User $user = null): void
    {
        $this->store($user)->remove($productId, $variantId);
    }

    public function clear(?User $user = null): void
    {
        $this->store($user)->clear();
    }

    /**
     * The cart with its products loaded, ready to render or price.
     *
     * Lines whose product has since been withdrawn are dropped rather than
     * shown as broken — and dropped from storage too, so the buyer is not
     * asked about them again.
     *
     * @return Collection<int, CartLine>
     */
    public function lines(?User $user = null): Collection
    {
        $store = $this->store($user);
        $lines = $store->lines();

        if ($lines->isEmpty()) {
            return $lines;
        }

        $products = Product::query()
            ->with(['priceTiers', 'images', 'seller', 'category'])
            ->whereIn('id', $lines->pluck('productId')->unique())
            ->get()
            ->keyBy('id');

        $variants = ProductVariant::query()
            ->whereIn('id', $lines->pluck('variantId')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $lines
            ->map(function (CartLine $line) use ($products, $variants, $store): ?CartLine {
                $product = $products->get($line->productId);

                if ($product === null
                    || ! $product->status->isPubliclyVisible()
                    || ! $product->seller?->isApproved()
                ) {
                    $store->remove($line->productId, $line->variantId);

                    return null;
                }

                return $line->withProduct(
                    $product,
                    $line->variantId === null ? null : $variants->get($line->variantId),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * The cart grouped the way it will be split into sub-orders: one group per
     * seller.
     *
     * @return Collection<int, array{seller: SellerProfile, lines: Collection<int, CartLine>, subtotal_kobo: int}>
     */
    public function groupedBySeller(?User $user = null): Collection
    {
        return $this->lines($user)
            ->groupBy(fn (CartLine $line): int => $line->product->seller_id)
            ->map(fn (Collection $lines): array => [
                'seller' => $lines->first()->product->seller,
                'lines' => $lines->values(),
                'subtotal_kobo' => $lines->sum(fn (CartLine $line): int => $line->lineTotalKobo()),
            ])
            ->values();
    }

    public function subtotalKobo(?User $user = null): int
    {
        return $this->lines($user)->sum(fn (CartLine $line): int => $line->lineTotalKobo());
    }

    public function count(?User $user = null): int
    {
        return $this->lines($user)->sum(fn (CartLine $line): int => $line->quantity);
    }

    /**
     * Move a guest's cart into their account on sign-in.
     *
     * Quantities are added together rather than replaced: somebody who put two
     * bags in before signing in and had one already meant to have three, not
     * one of them silently thrown away.
     */
    public function mergeGuestCartInto(User $user): int
    {
        $guest = new SessionCartStore($this->session);
        $guestLines = $guest->lines();

        if ($guestLines->isEmpty()) {
            return 0;
        }

        $database = new DatabaseCartStore($user);
        $existing = $database->lines()->keyBy(fn (CartLine $line): string => $line->key());

        $products = Product::query()
            ->with('priceTiers')
            ->whereIn('id', $guestLines->pluck('productId')->unique())
            ->get()
            ->keyBy('id');

        $merged = 0;

        foreach ($guestLines as $line) {
            $product = $products->get($line->productId);

            if ($product === null || ! $product->status->isPubliclyVisible()) {
                continue;
            }

            $variant = $line->variantId === null ? null : ProductVariant::query()->find($line->variantId);
            $quantity = $line->quantity + ($existing->get($line->key())?->quantity ?? 0);
            $quantity = $this->clampToStock($product, $variant, $quantity);

            $database->put(new CartLine(
                productId: $line->productId,
                variantId: $line->variantId,
                quantity: $quantity,
                unitPriceKobo: $product->unitPriceKoboFor($quantity, $variant),
            ));

            $merged++;
        }

        $guest->clear();

        return $merged;
    }

    private function find(CartStore $store, int $productId, ?int $variantId): ?CartLine
    {
        return $store->lines()->first(
            fn (CartLine $line): bool => $line->productId === $productId && $line->variantId === $variantId,
        );
    }

    /**
     * Never let a cart hold more than the seller has, and never less than the
     * listing's minimum order.
     */
    private function clampToStock(Product $product, ?ProductVariant $variant, int $quantity): int
    {
        $available = $variant?->stock_quantity ?? $product->stock_quantity;

        $quantity = max($quantity, $product->min_order_quantity);

        return $available > 0 ? min($quantity, $available) : $quantity;
    }
}
