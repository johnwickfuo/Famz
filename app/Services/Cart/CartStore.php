<?php

namespace App\Services\Cart;

use Illuminate\Support\Collection;

/**
 * Where a cart lives.
 *
 * Two implementations: the session for a guest, the database for somebody
 * signed in. The distinction exists because a guest's cart should not outlive
 * their browser, while a signed-in buyer expects theirs on their next phone.
 *
 * @method Collection<int, CartLine> lines()
 */
interface CartStore
{
    /**
     * @return Collection<int, CartLine>
     */
    public function lines(): Collection;

    public function put(CartLine $line): void;

    public function remove(int $productId, ?int $variantId): void;

    public function clear(): void;

    public function isEmpty(): bool;
}
