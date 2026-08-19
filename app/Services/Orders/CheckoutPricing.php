<?php

namespace App\Services\Orders;

use App\Services\Cart\CartLine;
use Illuminate\Support\Collection;

/**
 * A cart repriced at checkout.
 *
 * Prices are resolved again here rather than trusted from the cart, because a
 * seller may have changed them while the buyer shopped. Where a price has
 * moved, the new one is used and the change is reported so the buyer can be
 * told before they pay — a cart that silently reprices itself between the shelf
 * and the till is how people stop trusting a marketplace.
 */
final class CheckoutPricing
{
    /**
     * @param  array<int, array{name: string, was_kobo: int, now_kobo: int}>  $changes
     */
    private function __construct(
        public readonly array $changes,
    ) {}

    /**
     * @param  Collection<int, CartLine>  $lines
     */
    public static function forLines($lines): self
    {
        $changes = [];

        foreach ($lines as $line) {
            if (! $line->priceHasChanged()) {
                continue;
            }

            $changes[] = [
                'name' => $line->product->name,
                'was_kobo' => $line->unitPriceKobo,
                'now_kobo' => (int) $line->currentUnitPriceKobo(),
            ];
        }

        return new self($changes);
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }
}
