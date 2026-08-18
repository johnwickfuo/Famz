<?php

namespace App\Services\Catalogue;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * How many visible listings sit under each category, counting the whole
 * subtree.
 *
 * A direct count is misleading here: products are filed on leaves, so "Live
 * birds" would show zero while the day-old chicks filed beneath it are on sale.
 * A zero beside a category that leads somewhere is worse than no number at all.
 *
 * Two queries, whatever the shape of the tree: one for the categories, one for
 * the counts.
 */
class CategoryCounts
{
    /**
     * @var array<int, int>|null
     */
    private ?array $subtreeCounts = null;

    /**
     * @return array<int, int> category id => listings in its subtree
     */
    public function all(): array
    {
        if ($this->subtreeCounts !== null) {
            return $this->subtreeCounts;
        }

        /** @var Collection<int, object{id: int, parent_id: int|null}> $categories */
        $categories = Category::query()->get(['id', 'parent_id']);

        $direct = Product::query()
            ->visible()
            ->selectRaw('category_id, count(*) as aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $childrenOf = [];

        foreach ($categories as $category) {
            $childrenOf[$category->parent_id ?? 0][] = $category->id;
        }

        $totals = [];

        // Depth-first with an explicit stack, so a deep or accidentally
        // circular tree cannot blow the call stack.
        $resolve = function (int $id) use (&$resolve, &$totals, $childrenOf, $direct): int {
            if (array_key_exists($id, $totals)) {
                return $totals[$id];
            }

            // Seed before recursing: a cycle then resolves to what is already
            // counted rather than looping forever.
            $totals[$id] = $direct[$id] ?? 0;

            foreach ($childrenOf[$id] ?? [] as $childId) {
                $totals[$id] += $resolve($childId);
            }

            return $totals[$id];
        };

        foreach ($categories as $category) {
            $resolve($category->id);
        }

        return $this->subtreeCounts = $totals;
    }

    public function for(Category|int $category): int
    {
        $id = $category instanceof Category ? $category->getKey() : $category;

        return $this->all()[$id] ?? 0;
    }
}
