<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Gemini-backed resolver.
 *
 * Three rules govern everything here, and they are all about not trusting it:
 *
 *  1. It answers from a CLOSED vocabulary. The prompt hands it the tag slugs
 *     that exist and the parser discards anything else, so a hallucinated
 *     "poultry-whispering" is dropped rather than stored.
 *
 *  2. It has a short timeout and never retries. A farmer waiting on a
 *     shortlist should not wait thirty seconds for a service that may be down;
 *     the keyword matcher is right there and costs nothing.
 *
 *  3. Every failure returns `unavailable()` rather than throwing. Matching
 *     must never depend on an external service being up, and the way to
 *     guarantee that is for this class to have no path that breaks a caller.
 */
class GeminiTagResolver implements TagResolver
{
    /**
     * Short on purpose. See rule 2.
     */
    public const TIMEOUT_SECONDS = 6;

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    public function name(): string
    {
        return 'gemini';
    }

    /**
     * @param  array<int, array{slug: string, name: string, description: string|null}>  $vocabulary
     */
    public function suggest(string $need, array $vocabulary): TagSuggestion
    {
        if (! $this->isConfigured()) {
            return TagSuggestion::unavailable(__('No API key is configured.'));
        }

        if (trim($need) === '' || $vocabulary === []) {
            return TagSuggestion::unavailable(__('Nothing to work from.'));
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                ->post($this->endpoint(), $this->payload($need, $vocabulary));

            if ($response->failed()) {
                return $this->decline('The provider answered '.$response->status().'.', $need);
            }

            $slugs = $this->parse($response->json(), $vocabulary);

            if ($slugs === []) {
                return $this->decline('The provider named no tag we recognise.', $need);
            }

            return TagSuggestion::of($slugs);
        } catch (Throwable $exception) {
            // Timeouts, DNS, TLS, a malformed body — all the same answer.
            return $this->decline($exception->getMessage(), $need);
        }
    }

    private function endpoint(): string
    {
        $model = (string) config('services.gemini.model', 'gemini-2.0-flash');

        return rtrim((string) config('services.gemini.base_url'), '/')
            ."/models/{$model}:generateContent";
    }

    /**
     * @param  array<int, array{slug: string, name: string, description: string|null}>  $vocabulary
     * @return array<string, mixed>
     */
    private function payload(string $need, array $vocabulary): array
    {
        $tags = collect($vocabulary)
            ->map(fn (array $tag): string => sprintf('%s — %s', $tag['slug'], $tag['name']))
            ->implode("\n");

        return [
            'systemInstruction' => [
                'parts' => [[
                    'text' => implode(' ', [
                        'You match a Nigerian farmer\'s description of what they need help with',
                        'to tags from a fixed list. Choose only tags from the list.',
                        'Choose between one and five, most relevant first.',
                        'If nothing fits, return an empty list.',
                        'Answer with JSON only, of the form {"slugs":["tag-slug"]}.',
                    ]),
                ]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => "Tags:\n{$tags}\n\nWhat the farmer said:\n".trim($need),
                ]],
            ]],
            'generationConfig' => [
                // Deterministic: the same need should shortlist the same
                // mentors today and tomorrow.
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'maxOutputTokens' => 256,
            ],
        ];
    }

    /**
     * Pull the slugs out, keeping only ones that really exist.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<int, array{slug: string, name: string, description: string|null}>  $vocabulary
     * @return array<int, string>
     */
    private function parse(?array $body, array $vocabulary): array
    {
        $text = data_get($body, 'candidates.0.content.parts.0.text');

        if (! is_string($text)) {
            return [];
        }

        $decoded = json_decode(trim($text), true);

        $slugs = is_array($decoded) ? ($decoded['slugs'] ?? $decoded) : [];

        if (! is_array($slugs)) {
            return [];
        }

        $known = collect($vocabulary)->pluck('slug')->all();

        return collect($slugs)
            ->filter(fn ($slug): bool => is_string($slug))
            ->map(fn (string $slug): string => trim($slug))
            // The closed-vocabulary rule, enforced rather than requested.
            ->filter(fn (string $slug): bool => in_array($slug, $known, true))
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * Log it and decline. Never throws — see rule 3.
     */
    private function decline(string $why, string $need): TagSuggestion
    {
        Log::info('Mentor tag resolution fell back to keywords.', [
            'reason' => $why,
            // The first words only: enough to recognise the case later without
            // filling the log with somebody's business.
            'need' => mb_substr($need, 0, 120),
        ]);

        return TagSuggestion::unavailable($why);
    }
}
