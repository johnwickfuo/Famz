<?php

namespace App\Http\Controllers;

use App\Services\Platform\GlobalSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one search box in the header.
 *
 * Answers across the whole platform rather than only the marketplace, which is
 * what it did before. Somebody typing "broiler" into a box at the top of a
 * farming site has not decided whether they want feed, a course, a mentor or a
 * job, and making them decide first is making them learn the platform's
 * internal shape before it will help them.
 */
class SearchController extends Controller
{
    public function __construct(private readonly GlobalSearch $search) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'in:'.implode(',', GlobalSearch::types())],
        ]);

        $type = $validated['type'] ?? null;

        $results = $this->search->search(
            $validated['q'] ?? '',
            $type === null ? [] : [$type],
        );

        return Inertia::render('Search/Index', [
            ...$results,
            'type' => $type,
            'types' => collect(GlobalSearch::types())
                ->map(fn (string $value): array => ['value' => $value, 'label' => $this->label($value)])
                ->all(),
            'minLength' => GlobalSearch::MIN_LENGTH,
        ]);
    }

    private function label(string $type): string
    {
        return match ($type) {
            'products' => __('In the market'),
            'courses' => __('Training'),
            'mentors' => __('Mentors'),
            'jobs' => __('Farm jobs'),
            'requests' => __('People wanting to buy'),
            default => $type,
        };
    }
}
