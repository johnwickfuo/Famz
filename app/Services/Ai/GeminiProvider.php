<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gemini Flash, behind the contract.
 *
 * Flash rather than Pro because of what this is actually for. The model here is
 * doing language and light reasoning over figures it has been handed — it is
 * explicitly forbidden from doing arithmetic or recalling numbers — and Flash
 * is more than good enough for that at a fraction of the cost. The assistant is
 * free to anybody who lands on the site, so cost per answer is a product
 * constraint rather than an optimisation.
 *
 * Three rules, all of them about not depending on it:
 *
 *  1. A short timeout and no retry. Somebody on a phone in a poultry house
 *     should get an answer or an apology, not a thirty-second wait.
 *  2. Every failure returns AiResponse::failure() rather than throwing.
 *  3. Token counts come from the API where it reports them and are estimated
 *     where it does not, because a spend figure nobody can see is a spend
 *     figure that surprises somebody at the end of the month.
 */
class GeminiProvider implements AiProvider
{
    public const TIMEOUT_SECONDS = 20;

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return (string) config('services.gemini.model', 'gemini-2.0-flash');
    }

    /**
     * @param  array<int, AiMessage>  $history
     */
    public function chat(string $systemPrompt, array $history, string $message): AiResponse
    {
        if (! $this->isConfigured()) {
            return AiResponse::failure(__('No API key is configured.'));
        }

        $started = microtime(true);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                ->post($this->endpoint(), $this->payload($systemPrompt, $history, $message));
        } catch (Throwable $exception) {
            Log::warning('The assistant could not reach Gemini.', ['error' => $exception->getMessage()]);

            return AiResponse::failure(__('Could not reach the model.'), $this->elapsed($started));
        }

        $latency = $this->elapsed($started);

        if (! $response->successful()) {
            Log::warning('Gemini refused the request.', [
                'status' => $response->status(),
                // Truncated: the body can be long and may echo the prompt back,
                // which is not something to write into a log file wholesale.
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return AiResponse::failure(__('The model refused the request.'), $latency);
        }

        $text = $this->extractText($response->json());

        if ($text === null) {
            return AiResponse::failure(__('The model returned nothing usable.'), $latency);
        }

        $usage = $response->json('usageMetadata') ?? [];

        return AiResponse::success(
            text: $text,
            promptTokens: (int) ($usage['promptTokenCount'] ?? $this->estimateTokens($systemPrompt.$message)),
            completionTokens: (int) ($usage['candidatesTokenCount'] ?? $this->estimateTokens($text)),
            latencyMs: $latency,
            meta: ['model' => $this->model(), 'finish_reason' => $response->json('candidates.0.finishReason')],
        );
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('services.gemini.base_url'), '/');

        return $base.'/models/'.$this->model().':generateContent';
    }

    /**
     * @param  array<int, AiMessage>  $history
     * @return array<string, mixed>
     */
    private function payload(string $systemPrompt, array $history, string $message): array
    {
        $contents = [];

        foreach ($history as $turn) {
            $contents[] = [
                // Gemini calls the assistant "model".
                'role' => $turn->role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn->content]],
            ];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        return [
            // A real system instruction rather than a first user turn, so the
            // rules cannot be argued with by anything later in the conversation.
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $contents,
            'generationConfig' => [
                /*
                 * Low, not zero. The answers are short practical guidance in
                 * two languages, and a little variation reads better than a
                 * template — but every figure comes from context, so there is
                 * nothing here that variation is allowed to move.
                 */
                'temperature' => 0.3,
                'maxOutputTokens' => 700,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extractText(?array $json): ?string
    {
        $parts = $json['candidates'][0]['content']['parts'] ?? null;

        if (! is_array($parts)) {
            return null;
        }

        $text = collect($parts)->pluck('text')->filter()->implode('');

        return trim($text) === '' ? null : trim($text);
    }

    /**
     * A rough token count for when the API does not report one.
     *
     * Four characters to a token is the usual rule of thumb: wrong in detail,
     * right enough to keep a daily budget honest, which is all it is for.
     */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
