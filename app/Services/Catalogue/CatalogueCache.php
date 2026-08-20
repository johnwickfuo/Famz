<?php

namespace App\Services\Catalogue;

use Illuminate\Support\Facades\Cache;

/**
 * The blocks that are the same for everybody, kept in Redis.
 *
 * The category tree and its listing counts are identical for every visitor and
 * change only when an administrator edits a category or a listing is approved.
 * Recomputing them on every request means the two queries behind them run on
 * every page load of the busiest page on the site, forever, to produce a byte-
 * identical answer.
 *
 * The assumption behind all of this is a farmer on mobile data with a weak
 * signal. Server time is not the constraint there — the round trip is — but
 * every millisecond the server spends is a millisecond before the first byte
 * moves, and on a 3G connection that is the part of the wait the user actually
 * notices first.
 *
 * Busting is explicit rather than time-based. A seller whose listing is
 * approved should see the count move, and "within the hour" is not an answer
 * that satisfies anybody.
 */
class CatalogueCache
{
    /**
     * Long, because busting is explicit. The TTL is a safety net for a bust
     * that never fires, not the mechanism.
     */
    private const TTL_SECONDS = 86400;

    private const TREE_KEY = 'catalogue:featured-tree';

    private const COUNTS_KEY = 'catalogue:category-counts';

    private const HOME_KEY = 'catalogue:home-newest';

    /**
     * @param  callable(): array<int, mixed>  $build
     * @return array<int, mixed>
     */
    public function featuredTree(callable $build): array
    {
        return Cache::remember(self::TREE_KEY, self::TTL_SECONDS, $build);
    }

    /**
     * @param  callable(): array<int, int>  $build
     * @return array<int, int>
     */
    public function categoryCounts(callable $build): array
    {
        return Cache::remember(self::COUNTS_KEY, self::TTL_SECONDS, $build);
    }

    /**
     * @param  callable(): mixed  $build
     */
    public function homeListings(callable $build): mixed
    {
        return Cache::remember(self::HOME_KEY, self::TTL_SECONDS, $build);
    }

    /**
     * Everything that depends on the shape of the catalogue.
     *
     * One method rather than three, called from every write that could change
     * any of them. Three separate busts is three chances to forget one, and
     * the failure mode — a count that is wrong until somebody restarts Redis —
     * is invisible until a seller complains.
     */
    public function flush(): void
    {
        Cache::forget(self::TREE_KEY);
        Cache::forget(self::COUNTS_KEY);
        Cache::forget(self::HOME_KEY);
    }
}
