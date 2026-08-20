<?php

namespace App\Services\Ai\Chat;

use App\Models\BreedStandard;

/**
 * Working out what was asked, in English or Pidgin.
 *
 * Regular expressions rather than a model call. This has to be fast, free and
 * testable, and spending an API call to decide whether to make an API call is a
 * cost with no benefit.
 *
 * The Pidgin patterns are not decoration. "How much feed my 200 broiler go
 * chop" is how a great many people on this platform will actually ask, and a
 * classifier that only understood standard English would send every one of
 * those questions down the no-figures path.
 */
class IntentClassifier
{
    /**
     * Words that mean somebody is asking about a drug.
     *
     * This check runs FIRST and wins outright. A wrong dosage kills birds, and
     * an assistant that is confidently approximate about milligrams per litre
     * is worse than one that declines — so these go to a consultation with a
     * real vet, always, and no context is assembled for them at all.
     */
    private const DOSAGE_TERMS = [
        'dosage', 'dose', 'dosing', 'how much drug', 'how many ml', 'mg per',
        'ml per litre', 'ml per liter', 'antibiotic', 'antibiotics',
        'tylosin', 'oxytetracycline', 'enrofloxacin', 'amprolium', 'levamisole',
        'ivermectin', 'albendazole', 'sulphadimidine', 'sulfadimidine',
        'coccidiostat', 'vaccinate against', 'vaccine dose', 'inject',
        'injection', 'prescribe', 'prescription', 'treat am with', 'medicine for',
        'drug for', 'wetin i go give am', 'which drug', 'what drug',
    ];

    /**
     * Words that mean feed, in both languages.
     */
    private const FEED_TERMS = [
        'feed', 'feeding', 'consumption', 'eat', 'chop', 'ration', 'bag',
        'kg of feed', 'how much food', 'intake',
    ];

    private const PRICE_TERMS = [
        'price', 'cost', 'how much be', 'how much is', 'how much na',
        'rate', 'sell for', 'budget', 'expensive', 'cheap', 'na how much',
    ];

    /**
     * Words that only really turn up in Nigerian Pidgin.
     *
     * Used to pick the wording of the answers the platform writes itself — the
     * dosage redirect, the "I cannot reach the model" apology. Those never go
     * near a model, so nothing else is going to notice that the question was in
     * Pidgin and answer in kind, and a farmer who wrote in Pidgin and got a
     * stiff English refusal back has been told, fairly clearly, that this was
     * not built for them.
     *
     * Only used for canned text. A real answer's language is the model's job.
     */
    private const PIDGIN_MARKERS = [
        'abeg', 'wetin', 'wahala', 'sabi', 'dey', 'una', 'oga', 'i wan',
        'i no', 'no be', 'na so', 'make i', 'make e', 'fit ', 'go fit',
        'small small', 'how far', 'e get', 'my pikin', 'i go', 'e be like',
        'shey', 'abi', ' na ', 'chop',
    ];

    public function looksLikePidgin(string $message): bool
    {
        // Padded so ' na ' and 'fit ' can match at either end of the string.
        return $this->matchesAny(' '.mb_strtolower(trim($message)).' ', self::PIDGIN_MARKERS);
    }

    public function classify(string $message): QuestionIntent
    {
        $text = mb_strtolower(trim($message));

        // Dosage first, and it wins outright.
        if ($this->matchesAny($text, self::DOSAGE_TERMS)) {
            return QuestionIntent::of(QuestionIntent::DOSAGE);
        }

        $wantsFeed = $this->matchesAny($text, self::FEED_TERMS);
        $wantsPrice = $this->matchesAny($text, self::PRICE_TERMS);

        /*
         * "How much will it cost to feed 500 broilers" is both, and the price
         * of feed is the part that needs a market figure. Price wins when both
         * fire, and the feed slots are carried along so the assembler can
         * fetch both.
         */
        if ($wantsPrice) {
            return QuestionIntent::of(QuestionIntent::PRICE, [
                ...$this->feedSlots($text),
                'keyword_group' => $this->keywordGroup($text),
                'state' => $this->state($text),
                'also_feed' => $wantsFeed,
            ]);
        }

        if ($wantsFeed) {
            return QuestionIntent::of(QuestionIntent::FEED, $this->feedSlots($text));
        }

        return QuestionIntent::of(QuestionIntent::GENERAL);
    }

    /**
     * The bits of a feed question: how many birds, which breed, which weeks.
     *
     * @return array<string, mixed>
     */
    private function feedSlots(string $text): array
    {
        return [
            'bird_count' => $this->birdCount($text),
            'breed' => $this->breed($text),
            'from_week' => $this->weekRange($text)[0],
            'to_week' => $this->weekRange($text)[1],
            'species' => $this->species($text),
        ];
    }

    /**
     * How many birds.
     *
     * Handles "500", "1,500", "1500 birds" and "2k". Deliberately ignores a
     * number immediately followed by a week word, so "week 6" is not read as a
     * flock of six.
     */
    private function birdCount(string $text): ?int
    {
        // "2k", "1.5k"
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*k\b/', $text, $m) === 1) {
            return (int) round(((float) $m[1]) * 1000);
        }

        if (preg_match_all('/\b(\d{1,3}(?:,\d{3})+|\d+)\b(?!\s*(?:week|weeks|wk|wks|day|days|month|months))/', $text, $matches) === false) {
            return null;
        }

        $numbers = collect($matches[1] ?? [])
            ->map(fn (string $n): int => (int) str_replace(',', '', $n))
            // A flock is not two birds and not two million. Anything outside
            // that is almost certainly a week, a price or a typo.
            ->filter(fn (int $n): bool => $n >= 5 && $n <= 1_000_000);

        return $numbers->isEmpty() ? null : $numbers->max();
    }

    /**
     * Which breed, matched against what the platform actually has tables for.
     *
     * Reading the vocabulary out of the database rather than hard-coding it
     * means an administrator who adds a breed gets it recognised without a
     * code change.
     */
    private function breed(string $text): ?string
    {
        $breeds = BreedStandard::query()->active()->distinct()->pluck('breed');

        foreach ($breeds as $breed) {
            $needle = mb_strtolower($breed);

            if (str_contains($text, $needle)) {
                return $breed;
            }

            // "ross308" as well as "ross 308".
            if (str_contains(str_replace(' ', '', $text), str_replace(' ', '', $needle))) {
                return $breed;
            }
        }

        return null;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function weekRange(string $text): array
    {
        // "week 3 to 6", "weeks 3-6"
        if (preg_match('/weeks?\s*(\d{1,2})\s*(?:to|-|–|till|until)\s*(\d{1,2})/', $text, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        // "to week 6", "by week 6", "up to week 6" — a range from the start.
        if (preg_match('/(?:to|till|until|by|reach)\s*week\s*(\d{1,2})/', $text, $m) === 1) {
            return [1, (int) $m[1]];
        }

        // "week 6", "6 weeks old", "6 weeks"
        if (preg_match('/(?:week\s*(\d{1,2})|(\d{1,2})\s*weeks?)/', $text, $m) === 1) {
            $week = (int) ($m[1] !== '' ? $m[1] : $m[2]);

            return [$week, $week];
        }

        return [null, null];
    }

    private function species(string $text): ?string
    {
        foreach ([
            'Goat' => ['goat', 'ewure'],
            'Sheep' => ['sheep', 'ram'],
            'Cattle' => ['cattle', 'cow', 'bull', 'calf'],
            'Pig' => ['pig', 'piglet', 'sow', 'boar'],
            'Catfish' => ['catfish', 'fish', 'fingerling', 'pond'],
            'Turkey' => ['turkey'],
            'Chicken' => ['chicken', 'broiler', 'layer', 'cockerel', 'pullet', 'chick', 'noiler', 'bird'],
        ] as $species => $terms) {
            if ($this->matchesAny($text, $terms)) {
                return $species;
            }
        }

        return null;
    }

    /**
     * Which priced thing is being asked about.
     */
    private function keywordGroup(string $text): ?string
    {
        foreach ([
            'broiler starter feed' => [['broiler', 'starter']],
            'broiler finisher feed' => [['broiler', 'finisher']],
            'layer mash' => [['layer', 'mash'], ['layer', 'feed']],
            'grower mash' => [['grower']],
            'chick mash' => [['chick', 'mash']],
            'day old broiler chicks' => [['day old', 'broiler'], ['doc', 'broiler']],
            'day old layer chicks' => [['day old', 'layer'], ['doc', 'layer']],
            'point of lay pullets' => [['point of lay'], ['pullet']],
            'noiler chicks' => [['noiler']],
            'table eggs' => [['egg']],
            'fish feed' => [['fish feed'], ['catfish feed']],
            'catfish fingerlings' => [['fingerling']],
            'maize' => [['maize'], ['corn']],
            'soya meal' => [['soya'], ['soybean']],
            'wheat offal' => [['wheat offal'], ['wheat bran']],
            'palm kernel cake' => [['palm kernel']],
        ] as $group => $termSets) {
            foreach ($termSets as $terms) {
                $all = true;

                foreach ($terms as $term) {
                    if (! str_contains($text, $term)) {
                        $all = false;

                        break;
                    }
                }

                if ($all) {
                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * Which state, if one was named.
     */
    private function state(string $text): ?string
    {
        foreach (\App\Support\Nigeria::states() as $state) {
            if (str_contains($text, mb_strtolower($state))) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function matchesAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }
}
