<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A signed-in buyer's cart, in the database, so it is still there on their next
 * phone or after their session expires.
 */
class DatabaseCartStore implements CartStore
{
    private ?Cart $cart = null;

    public function __construct(private readonly User $user) {}

    /**
     * @return Collection<int, CartLine>
     */
    public function lines(): Collection
    {
        return $this->cart()
            ->items()
            ->get()
            ->map(fn (CartItem $item): CartLine => new CartLine(
                productId: $item->product_id,
                variantId: $item->product_variant_id,
                quantity: $item->quantity,
                unitPriceKobo: $item->unit_price_kobo,
            ))
            ->values();
    }

    public function put(CartLine $line): void
    {
        $this->cart()->items()->updateOrCreate(
            [
                'product_id' => $line->productId,
                'product_variant_id' => $line->variantId,
            ],
            [
                'quantity' => $line->quantity,
                'unit_price_kobo' => $line->unitPriceKobo,
            ],
        );
    }

    public function remove(int $productId, ?int $variantId): void
    {
        $this->cart()->items()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->delete();
    }

    public function clear(): void
    {
        $this->cart()->items()->delete();
    }

    public function isEmpty(): bool
    {
        return ! $this->cart()->items()->exists();
    }

    private function cart(): Cart
    {
        return $this->cart ??= Cart::query()->firstOrCreate(['user_id' => $this->user->getKey()]);
    }
}
