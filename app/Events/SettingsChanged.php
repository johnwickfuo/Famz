<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised whenever anything in the settings table is written or cleared, so
 * derived caches (branding, for one) can drop themselves immediately.
 */
class SettingsChanged
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $keys  Keys that changed; empty means "all of them".
     */
    public function __construct(public readonly array $keys = []) {}

    public function touches(string $key): bool
    {
        return $this->keys === [] || in_array($key, $this->keys, true);
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function touchesAny(array $keys): bool
    {
        return $this->keys === [] || array_intersect($this->keys, $keys) !== [];
    }
}
