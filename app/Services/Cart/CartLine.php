<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * One line in a cart, independent of where the cart is stored.
 *
 * The unit price is the one resolved when the item went in. It is compared
 * against the live price at checkout so the buyer can be told if it moved.
 */
final class CartLine
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly int $quantity,
        public readonly int $unitPriceKobo,
        public readonly ?Product $product = null,
        public readonly ?ProductVariant $variant = null,
    ) {}

    /**
     * Identifies a line within a cart: the same product with a different option
     * is a different line.
     */
    public function key(): string
    {
        return $this->productId.':'.($this->variantId ?? '0');
    }

    public function lineTotalKobo(): int
    {
        return $this->unitPriceKobo * $this->quantity;
    }

    public function withQuantity(int $quantity): self
    {
        return new self(
            $this->productId,
            $this->variantId,
            $quantity,
            $this->unitPriceKobo,
            $this->product,
            $this->variant,
        );
    }

    public function withProduct(?Product $product, ?ProductVariant $variant): self
    {
        return new self(
            $this->productId,
            $this->variantId,
            $this->quantity,
            $this->unitPriceKobo,
            $product,
            $variant,
        );
    }

    /**
     * What this line would cost if it were added right now.
     *
     * Bulk tiers and the option's price difference both apply, exactly as they
     * do on the product page — see Product::unitPriceKoboFor().
     */
    public function currentUnitPriceKobo(): ?int
    {
        if ($this->product === null) {
            return null;
        }

        return $this->product->unitPriceKoboFor($this->quantity, $this->variant);
    }

    public function priceHasChanged(): bool
    {
        $current = $this->currentUnitPriceKobo();

        return $current !== null && $current !== $this->unitPriceKobo;
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'quantity' => $this->quantity,
            'unit_price_kobo' => $this->unitPriceKobo,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: (int) $data['product_id'],
            variantId: isset($data['variant_id']) ? ((int) $data['variant_id'] ?: null) : null,
            quantity: (int) $data['quantity'],
            unitPriceKobo: (int) $data['unit_price_kobo'],
        );
    }
}
