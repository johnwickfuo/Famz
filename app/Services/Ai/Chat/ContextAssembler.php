<?php

namespace App\Services\Ai\Chat;

use App\Services\Ai\Retrieval\FeedCalculation;
use App\Services\Ai\Retrieval\FeedCalculatorService;
use App\Services\Ai\Retrieval\MarketPriceService;

/**
 * Fetching the figures an answer is allowed to contain.
 *
 * Everything the assistant may state as fact passes through here. The model is
 * never asked to recall a feed figure or a price; it is handed whatever this
 * class could find, and the system prompt forbids it from going beyond that.
 *
 * The interesting work is what happens when a figure is missing. There are
 * three ways this can go and only one of them is a lookup:
 *
 *  - The figure exists → it goes in as a fact, with its source.
 *  - The question is short of a detail (no breed, no flock size) → a note says
 *    what is missing, so the assistant asks for it instead of assuming.
 *  - The data is genuinely thin → a note says so in words, so the assistant
 *    says "I do not have that" rather than producing a plausible number.
 *
 * The second and third cases are the ones that make the assistant trustworthy.
 * A retrieval layer that returned nothing on a miss would leave the model with
 * an empty context and a question it feels able to answer, which is exactly the
 * situation that produces invented figures.
 */
class ContextAssembler
{
    public function __construct(
        private readonly FeedCalculatorService $feed,
        private readonly MarketPriceService $prices,
    ) {}

    public function assemble(QuestionIntent $intent): ContextBlock
    {
        return match (true) {
            // Dosage questions are redirected before they ever reach a model,
            // so there is nothing to retrieve and nothing to be tempted by.
            $intent->is(QuestionIntent::DOSAGE) => ContextBlock::empty(),
            $intent->is(QuestionIntent::FEED) => $this->feedContext($intent),
            $intent->is(QuestionIntent::PRICE) => $this->priceContext($intent),
            default => ContextBlock::empty(),
        };
    }

    /**
     * Feed, water and expected weight for a flock.
     */
    private function feedContext(QuestionIntent $intent): ContextBlock
    {
        $species = $intent->slot('species');
        $breed = $intent->slot('breed');

        // Goats, cattle, fish. A different question with a different table.
        if ($breed === null && $species !== null && ! in_array($species, ['Chicken', 'Turkey'], true)) {
            return $this->livestockContext($species);
        }

        if ($breed === null) {
            return ContextBlock::of([], [
                __('The question does not say which breed. Ask which one before giving any feed figure.'),
                $this->breedVocabulary(),
            ]);
        }

        $coverage = $this->feed->coverageFor($breed);

        if ($coverage === null) {
            return ContextBlock::of([], [
                __('There is no feeding table for :breed on this platform.', ['breed' => $breed]),
                $this->breedVocabulary(),
            ]);
        }

        [$fromWeek, $toWeek, $weekNote] = $this->weeks($intent, $coverage);

        if ($fromWeek === null || $toWeek === null) {
            return ContextBlock::of([], array_filter([
                $weekNote,
                $this->breedCoverageNote($coverage),
            ]));
        }

        /*
         * No flock size given is not a reason to refuse. Per-bird figures are
         * still useful — it is how the published tables are written — so the
         * sum runs for one bird and the note asks for the number.
         */
        $birdCount = $intent->slot('bird_count');
        $perBird = $birdCount === null;

        $calculation = $this->feed->forFlock(
            breed: $breed,
            birdCount: $perBird ? 1 : (int) $birdCount,
            fromWeek: $fromWeek,
            toWeek: $toWeek,
        );

        if (! $calculation->ok) {
            return ContextBlock::of([], array_filter([
                $calculation->reason,
                $this->breedCoverageNote($coverage),
            ]));
        }

        return ContextBlock::of(
            $this->feedFacts($calculation, $perBird),
            array_filter([
                $weekNote,
                /*
                 * The calculator stops at the end of the table rather than
                 * extrapolating, so a question reaching past it comes back
                 * covering fewer weeks than were asked about. Saying so is not
                 * optional: a farmer who asked about twelve weeks and is handed
                 * an eight-week total without being told would plan against it.
                 */
                $calculation->toWeek < $toWeek
                    ? __('The :breed table stops at week :last, so this covers weeks :from to :last only — not through to week :asked as asked.', [
                        'breed' => $calculation->breed,
                        'last' => $calculation->toWeek,
                        'from' => $calculation->fromWeek,
                        'asked' => $toWeek,
                    ])
                    : null,
                $perBird
                    ? __('No flock size was given, so these are per-bird figures. Ask how many birds and the totals can be given.')
                    : null,
            ]),
        );
    }

    /**
     * @return array<int, array{label: string, value: string, source: string, captured: string|null}>
     */
    private function feedFacts(FeedCalculation $calculation, bool $perBird): array
    {
        $source = implode('; ', $calculation->sources) ?: __('platform reference table');

        $subject = $perBird
            ? __('1 :breed bird', ['breed' => $calculation->breed])
            : __(':count :breed', ['count' => number_format($calculation->birdCount), 'breed' => $calculation->breed]);

        $period = $calculation->fromWeek === $calculation->toWeek
            ? __('week :week', ['week' => $calculation->fromWeek])
            : __('weeks :from to :to', ['from' => $calculation->fromWeek, 'to' => $calculation->toWeek]);

        $facts = [
            [
                'label' => __('Total feed for :subject, :period', ['subject' => $subject, 'period' => $period]),
                'value' => __(':kg kg, which is :bags bags of 25 kg', [
                    'kg' => $this->trim($calculation->totalFeedKg),
                    'bags' => $this->trim($calculation->bagsOf25Kg()),
                ]),
                'source' => $source,
                'captured' => null,
            ],
            [
                'label' => __('Drinking water over the same period'),
                'value' => __(':litres litres', ['litres' => $this->trim($calculation->totalWaterLitres)]),
                // Water is derived from feed by the multiplier on the same row,
                // so it carries the same citation rather than a separate one.
                'source' => $source,
                'captured' => null,
            ],
        ];

        if ($calculation->expectedWeightG !== null) {
            $facts[] = [
                'label' => __('Expected live weight at week :week', ['week' => $calculation->toWeek]),
                'value' => __(':grams g per bird', ['grams' => number_format($calculation->expectedWeightG)]),
                'source' => $source,
                'captured' => null,
            ];
        }

        /*
         * The week-by-week breakdown, capped. A farmer asking about a laying
         * cycle would otherwise get seventy-two lines of context, most of the
         * prompt budget spent on rows nobody asked about, and an answer that
         * reads like a spreadsheet.
         */
        foreach (array_slice($calculation->weeks, 0, 12) as $week) {
            $value = __(':grams g per bird per day', ['grams' => $this->trim($week['g_per_bird_per_day'])]);

            if (! $perBird) {
                $value .= __(', :kg kg for the flock that week', ['kg' => $this->trim($week['flock_kg_for_the_week'])]);
            }

            $facts[] = [
                'label' => __('Week :week', ['week' => $week['week']]),
                'value' => $value,
                // Saying which row a figure was read off is the difference
                // between a citation and a claim: layer tables are quoted at
                // intervals, so week 19 is genuinely read from week 18.
                'source' => $week['read_from_week'] === $week['week']
                    ? $source
                    : __(':source — read from the week :row row', ['source' => $source, 'row' => $week['read_from_week']]),
                'captured' => null,
            ];
        }

        return $facts;
    }

    /**
     * Goats, sheep, cattle, pigs, catfish.
     */
    private function livestockContext(string $species): ContextBlock
    {
        $standard = $this->feed->forLivestock($species);

        if ($standard === null) {
            return ContextBlock::of([], [
                __('There is no feeding standard for :species on this platform.', ['species' => $species]),
            ]);
        }

        $facts = [];

        if ($standard['feed_kg_per_day'] !== null) {
            $facts[] = [
                'label' => __('Daily feed for a :category :species', [
                    'category' => mb_strtolower((string) $standard['category']),
                    'species' => mb_strtolower($standard['species']),
                ]),
                'value' => __(':kg kg per day', ['kg' => $this->trim((float) $standard['feed_kg_per_day'])]),
                'source' => (string) $standard['source'],
                'captured' => null,
            ];
        }

        if ($standard['feed_percent_of_bodyweight'] !== null) {
            $facts[] = [
                'label' => __('Ration as a share of body weight'),
                'value' => __(':percent% of body weight per day', [
                    'percent' => $this->trim((float) $standard['feed_percent_of_bodyweight']),
                ]),
                'source' => (string) $standard['source'],
                'captured' => null,
            ];
        }

        if ($standard['water_litres_per_day'] !== null) {
            $facts[] = [
                'label' => __('Drinking water'),
                'value' => __(':litres litres per day', ['litres' => $this->trim((float) $standard['water_litres_per_day'])]),
                'source' => (string) $standard['source'],
                'captured' => null,
            ];
        }

        return ContextBlock::of($facts, array_filter([
            $standard['assumed_weight_kg'] === null
                ? null
                : __('These assume an animal of about :kg kg. Say so, and ask the real weight if it matters.', [
                    'kg' => $this->trim((float) $standard['assumed_weight_kg']),
                ]),
            $standard['notes'],
        ]));
    }

    /**
     * What something costs, from this platform's own listings.
     */
    private function priceContext(QuestionIntent $intent): ContextBlock
    {
        $group = $intent->slot('keyword_group');

        if ($group === null) {
            return ContextBlock::of([], array_filter([
                __('It is not clear what the price question is about, so no price was looked up.'),
                $this->priceVocabulary(),
            ]));
        }

        $resolved = $this->prices->resolve($group, $intent->slot('state'));

        if (! $resolved['ok']) {
            return ContextBlock::of([], array_filter([
                $resolved['reason'],
                __('Do not give a price for :thing. Say the marketplace does not have enough listings for it yet.', [
                    'thing' => $group,
                ]),
            ]));
        }

        $scope = $resolved['scope'] === 'national'
            ? __('Nigeria-wide')
            : __(':state State', ['state' => $resolved['scope']]);

        $facts = [
            [
                'label' => __('Typical price of :thing (:scope)', ['thing' => $group, 'scope' => $scope]),
                'value' => trim($resolved['median'].' '.($resolved['unit'] ? __('per :unit', ['unit' => $resolved['unit']]) : '')),
                'source' => $resolved['source'],
                'captured' => $resolved['captured_at'],
            ],
            [
                'label' => __('Range across those listings'),
                'value' => $resolved['range'],
                'source' => $resolved['source'],
                'captured' => $resolved['captured_at'],
            ],
            [
                // The sample size travels with the figure, always. "18,500 a
                // bag" and "18,500 a bag from 23 listings" are different claims
                // and only the second one is honest.
                'label' => __('How many listings this is based on'),
                'value' => trans_choice('{1} 1 listing|[2,*] :count listings', $resolved['sample_size'], [
                    'count' => number_format($resolved['sample_size']),
                ]),
                'source' => $resolved['source'],
                'captured' => $resolved['captured_at'],
            ],
        ];

        return ContextBlock::of($facts, array_filter([
            $resolved['note'],
            __('This is what sellers on this marketplace are asking, not an official price. Say that.'),
        ]));
    }

    /**
     * The week range to sum over, and anything worth saying about the choice.
     *
     * @param  array{species: string, breed: string, production_type: string, first_week: int, last_week: int}  $coverage
     * @return array{0: int|null, 1: int|null, 2: string|null}
     */
    private function weeks(QuestionIntent $intent, array $coverage): array
    {
        $from = $intent->slot('from_week');
        $to = $intent->slot('to_week');

        if ($from !== null && $to !== null) {
            return [(int) $from, (int) $to, null];
        }

        /*
         * "How much feed for 500 broilers" with no weeks named means the whole
         * cycle, and for a broiler the whole cycle is a real, short, answerable
         * thing. For a layer it is seventy-odd weeks, which is not what anybody
         * means — so that one asks instead of summing.
         */
        if (mb_strtolower($coverage['production_type']) === 'broiler') {
            return [
                $coverage['first_week'],
                $coverage['last_week'],
                __('No weeks were named, so this is the whole cycle, weeks :from to :to.', [
                    'from' => $coverage['first_week'],
                    'to' => $coverage['last_week'],
                ]),
            ];
        }

        return [null, null, __('No age or week was given, and a :type figure depends entirely on it. Ask how old the birds are.', [
            'type' => mb_strtolower($coverage['production_type']),
        ])];
    }

    /**
     * @param  array{species: string, breed: string, production_type: string, first_week: int, last_week: int}  $coverage
     */
    private function breedCoverageNote(array $coverage): string
    {
        return __('The :breed table covers weeks :from to :to.', [
            'breed' => $coverage['breed'],
            'from' => $coverage['first_week'],
            'to' => $coverage['last_week'],
        ]);
    }

    /**
     * The breeds there are tables for, so the assistant can ask a useful
     * question rather than a vague one.
     */
    private function breedVocabulary(): string
    {
        $breeds = collect($this->feed->knownBreeds())
            ->map(fn (array $breed): string => sprintf(
                '%s (%s, weeks %s)',
                $breed['breed'],
                mb_strtolower($breed['production_type']),
                $breed['weeks'],
            ))
            ->implode(', ');

        return $breeds === ''
            ? __('There are no feeding tables loaded on this platform at all.')
            : __('Feeding tables exist for: :breeds.', ['breeds' => $breeds]);
    }

    private function priceVocabulary(): ?string
    {
        $groups = $this->prices->knownGroups();

        return $groups === []
            ? null
            : __('The marketplace has recent prices for: :groups.', ['groups' => implode(', ', $groups)]);
    }

    /**
     * A number the way somebody would write it, not the way PHP prints a float.
     *
     * "1,526" rather than "1526.0", and "61.1" rather than "61.1000000001".
     */
    private function trim(float $value): string
    {
        $rounded = round($value, 2);

        return $rounded == (int) $rounded
            ? number_format((int) $rounded)
            : number_format($rounded, $this->decimals($rounded));
    }

    private function decimals(float $value): int
    {
        return round($value, 1) == $value ? 1 : 2;
    }
}
