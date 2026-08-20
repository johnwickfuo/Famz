<?php

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\MarketPriceSnapshot;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turning today's listings into prices the assistant can quote.
 *
 * This is the platform's own data, which is the whole argument for it. Scraping
 * a third-party site would give numbers that are stale the day after they are
 * taken and that nobody can verify; these come from sellers on this site, so
 * the assistant can say how many listings a figure is built on and when it was
 * captured — and the answer gets better as the marketplace grows rather than
 * rotting.
 *
 * The median is used rather than the mean. One seller listing day-old chicks at
 * a hundred times the going rate — a typo, or an attempt to game a search —
 * moves a mean and does not move a median.
 */
class CaptureMarketPrices extends Command
{
    protected $signature = 'market:capture-prices';

    protected $description = 'Aggregate active listings into today\'s market price snapshots';

    /**
     * The buckets products are sorted into.
     *
     * Products are named a hundred ways — "Top Feed Broiler Starter 25kg",
     * "broiler starter (25 kg bag)" — and a farmer asks about one thing. Each
     * bucket is a list of terms that all have to appear, so "broiler starter"
     * does not swallow "broiler finisher".
     *
     * @var array<string, array<int, array<int, string>>>
     */
    private const GROUPS = [
        'broiler starter feed' => [['broiler', 'starter']],
        'broiler finisher feed' => [['broiler', 'finisher']],
        'layer mash' => [['layer', 'mash'], ['layers', 'mash']],
        'grower mash' => [['grower', 'mash']],
        'chick mash' => [['chick', 'mash']],
        'day old broiler chicks' => [['day', 'old', 'broiler'], ['doc', 'broiler']],
        'day old layer chicks' => [['day', 'old', 'layer'], ['doc', 'layer']],
        'point of lay pullets' => [['point', 'lay'], ['pol', 'pullet']],
        'noiler chicks' => [['noiler']],
        'table eggs' => [['crate', 'egg'], ['table', 'egg']],
        'fish feed' => [['fish', 'feed'], ['catfish', 'feed']],
        'catfish fingerlings' => [['fingerling']],
        'maize' => [['maize']],
        'soya meal' => [['soya'], ['soybean']],
        'wheat offal' => [['wheat', 'offal']],
        'palm kernel cake' => [['palm', 'kernel']],
    ];

    public function handle(): int
    {
        $capturedAt = now();
        $written = 0;

        foreach (self::GROUPS as $group => $termSets) {
            $products = $this->productsFor($termSets);

            if ($products->isEmpty()) {
                continue;
            }

            // National first, then each state that has any listings at all.
            $written += $this->write($group, null, $products, $capturedAt);

            foreach ($products->groupBy('state') as $state => $inState) {
                if (blank($state)) {
                    continue;
                }

                $written += $this->write($group, (string) $state, $inState, $capturedAt);
            }
        }

        $this->info(sprintf('%d snapshot(s) written.', $written));

        return self::SUCCESS;
    }

    /**
     * Active listings matching any of the term sets.
     *
     * @param  array<int, array<int, string>>  $termSets
     * @return Collection<int, object>
     */
    private function productsFor(array $termSets): Collection
    {
        $query = Product::query()
            ->where('products.status', ProductStatus::Approved)
            ->join('seller_profiles', 'products.seller_id', '=', 'seller_profiles.id')
            ->select([
                'products.price_kobo',
                'products.unit_of_measure',
                'products.category_id',
                'seller_profiles.state',
            ]);

        $query->where(function ($outer) use ($termSets): void {
            foreach ($termSets as $terms) {
                $outer->orWhere(function ($inner) use ($terms): void {
                    // Every term in a set must appear, so "broiler starter"
                    // cannot match a finisher bag.
                    foreach ($terms as $term) {
                        $inner->where('products.name', 'like', '%'.$term.'%');
                    }
                });
            }
        });

        return $query->get();
    }

    /**
     * @param  Collection<int, object>  $products
     */
    private function write(string $group, ?string $state, Collection $products, Carbon $capturedAt): int
    {
        $prices = $products->pluck('price_kobo')->map(fn ($p): int => (int) $p)->sort()->values();

        if ($prices->isEmpty()) {
            return 0;
        }

        MarketPriceSnapshot::query()->updateOrCreate(
            [
                'keyword_group' => $group,
                'state' => $state,
                // One row per group, per place, per day. Re-running the command
                // corrects today rather than piling up duplicates.
                'captured_at' => $capturedAt->copy()->startOfDay(),
            ],
            [
                'category_id' => $products->pluck('category_id')->filter()->countBy()->sortDesc()->keys()->first(),
                'median_price_kobo' => $this->median($prices),
                'min_price_kobo' => $prices->first(),
                'max_price_kobo' => $prices->last(),
                'sample_size' => $prices->count(),
                'unit' => $products->pluck('unit_of_measure')->filter()->countBy()->sortDesc()->keys()->first(),
            ],
        );

        return 1;
    }

    /**
     * The middle value, averaging the two middles on an even count.
     *
     * @param  Collection<int, int>  $sorted
     */
    private function median(Collection $sorted): int
    {
        $count = $sorted->count();
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : (int) round(($sorted[$middle - 1] + $sorted[$middle]) / 2);
    }
}
