<?php

namespace App\Services\Mentorship;

use App\Models\MentorshipMatch;
use App\Models\Specialisation;
use App\Services\Ai\TagResolver;
use Illuminate\Support\Collection;

/**
 * Turning what somebody typed into tags.
 *
 * The AI layer is asked first and the keyword matcher answers whenever it does
 * not — which includes when there is no API key at all, so the default
 * configuration of this application takes the fallback path. That is
 * deliberate: the fallback is the thing that has to work, so it is the thing
 * that runs in development, in the tests and on any deployment where somebody
 * has not signed up to Google.
 *
 * The keyword matcher is not a stub. It scores every tag against the words in
 * the request using the taxonomy's own keyword lists, which is why the
 * SpecialisationSeeder writes the phrases a farmer would actually type.
 */
class SpecialisationMatcher
{
    public function __construct(private readonly TagResolver $ai) {}

    /**
     * @return array{ids: array<int, int>, resolver: string, note: string|null}
     */
    public function resolve(MatchRequest $request): array
    {
        $vocabulary = $this->vocabulary($request->sector);

        // The client ticked boxes themselves. Nothing to infer, and their own
        // choice beats anybody's guess about it.
        if ($request->specialisationIds !== []) {
            $known = $vocabulary->pluck('id')->all();

            $ids = array_values(array_intersect($request->specialisationIds, $known));

            if ($ids !== []) {
                return ['ids' => $ids, 'resolver' => MentorshipMatch::RESOLVER_EXPLICIT, 'note' => null];
            }
        }

        $suggestion = $this->ai->suggest(
            $request->need,
            $vocabulary->map(fn (Specialisation $tag): array => [
                'slug' => $tag->slug,
                'name' => $tag->name,
                'description' => $tag->description,
            ])->all(),
        );

        if ($suggestion->isUsable()) {
            $ids = $vocabulary->whereIn('slug', $suggestion->slugs)->pluck('id')->all();

            if ($ids !== []) {
                return [
                    'ids' => array_values($ids),
                    'resolver' => MentorshipMatch::RESOLVER_AI,
                    'note' => null,
                ];
            }
        }

        return [
            'ids' => $this->byKeyword($request->need, $vocabulary),
            'resolver' => MentorshipMatch::RESOLVER_KEYWORD,
            'note' => $suggestion->note,
        ];
    }

    /**
     * Score every tag against the words in the request.
     *
     * Longer phrases count for more than single words: somebody who wrote
     * "my day old chicks are dying" should land on brooding rather than on
     * every tag that happens to mention a chick.
     *
     * @param  Collection<int, Specialisation>  $vocabulary
     * @return array<int, int>
     */
    private function byKeyword(string $need, Collection $vocabulary): array
    {
        $haystack = ' '.preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($need)).' ';

        $scored = $vocabulary
            ->map(function (Specialisation $tag) use ($haystack): array {
                $score = 0;

                foreach ($tag->searchTerms() as $term) {
                    $clean = preg_replace('/[^a-z0-9]+/', ' ', $term);

                    if ($clean === '' || $clean === null) {
                        continue;
                    }

                    if (str_contains($haystack, ' '.$clean.' ')) {
                        // A two-word phrase is worth more than two one-word
                        // coincidences.
                        $score += 1 + substr_count(trim($clean), ' ');
                    }
                }

                return ['id' => $tag->id, 'score' => $score];
            })
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->take(5);

        return $scored->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * @return Collection<int, Specialisation>
     */
    private function vocabulary(?string $sector): Collection
    {
        return Specialisation::query()
            ->active()
            ->when(filled($sector), fn ($query) => $query->where('sector', $sector))
            ->ordered()
            ->get();
    }
}
