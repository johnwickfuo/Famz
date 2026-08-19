<?php

namespace App\Services\Cart;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * A guest's cart, held in the session.
 *
 * Deliberately not the database: a guest cart that outlived the browser would
 * be a row nobody will ever come back for, and there would be no way to tell
 * whose it was.
 */
class SessionCartStore implements CartStore
{
    public const SESSION_KEY = 'cart.lines';

    public function __construct(private readonly Session $session) {}

    /**
     * @return Collection<int, CartLine>
     */
    public function lines(): Collection
    {
        return collect($this->session->get(self::SESSION_KEY, []))
            ->map(fn (array $data): CartLine => CartLine::fromArray($data))
            ->values();
    }

    public function put(CartLine $line): void
    {
        $lines = $this->rawLines();
        $lines[$line->key()] = $line->toArray();

        $this->session->put(self::SESSION_KEY, $lines);
    }

    public function remove(int $productId, ?int $variantId): void
    {
        $lines = $this->rawLines();
        unset($lines[$productId.':'.($variantId ?? '0')]);

        $this->session->put(self::SESSION_KEY, $lines);
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    public function isEmpty(): bool
    {
        return $this->rawLines() === [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawLines(): array
    {
        return (array) $this->session->get(self::SESSION_KEY, []);
    }
}
