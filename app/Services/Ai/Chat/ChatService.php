<?php

namespace App\Services\Ai\Chat;

use App\Services\Ai\AiMessage;
use App\Services\Ai\AiProvider;
use App\Services\Branding\BrandingService;
use Illuminate\Support\Str;

/**
 * The assistant, assembled.
 *
 * The order of operations here is the safety design, so it is worth stating
 * plainly:
 *
 *  1. Classify the question with regular expressions. Free, fast, testable.
 *  2. If it is a drug question, answer it HERE and stop. No model call is made
 *     at all — the redirect to a consultation is written by the platform, so
 *     there is no prompt to argue with and no dosage that can slip out.
 *  3. Retrieve the figures, in PHP, from the platform's own tables and
 *     listings. All arithmetic happens in this step.
 *  4. Build a system prompt around those figures, with the company name read
 *     from BrandingService at this moment rather than baked into a string.
 *  5. Only now call the model, whose job is to put the retrieved figures into
 *     sentences in the language the farmer used.
 *
 * The model is the last step and the least trusted one. Everything that could
 * cause real harm — a wrong dose, an invented price, a fabricated company name
 * — has already been decided before it is asked anything.
 *
 * Caching, rate limits and the token budget wrap this class rather than living
 * inside it, so that this remains the answer to "what does the assistant say",
 * uncomplicated by "is this person allowed to ask".
 */
class ChatService
{
    /**
     * How much of a conversation goes back to the model.
     *
     * Enough to follow "and for 1,000 of them?" back to the bird it referred
     * to, and not so much that a long thread quietly triples the cost of every
     * subsequent answer.
     */
    public const HISTORY_TURNS = 8;

    /**
     * Longer than this and it is not a question, it is a paste.
     */
    public const MAX_MESSAGE_LENGTH = 1000;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly IntentClassifier $classifier,
        private readonly ContextAssembler $assembler,
        private readonly SystemPrompt $prompt,
        private readonly AnswerCache $cache,
        private readonly BrandingService $branding,
    ) {}

    /**
     * @param  array<int, AiMessage>  $history  Oldest first.
     * @param  array<string, mixed>  $options   `state` biases price lookups;
     *                                          `cache_only` forbids spending money on this one.
     */
    public function answer(string $message, array $history = [], array $options = []): ChatReply
    {
        $message = $this->tidy($message);
        $pidgin = $this->classifier->looksLikePidgin($message);

        if ($message === '') {
            return ChatReply::written(
                $pidgin ? __('Abeg, ask me something about your farm.') : __('Ask me something about your farm.'),
                QuestionIntent::GENERAL,
                ContextBlock::empty(),
            );
        }

        $intent = $this->classifier->classify($message);

        // Step 2. This returns before any model is involved, deliberately.
        if ($intent->is(QuestionIntent::DOSAGE)) {
            return ChatReply::written(
                $this->dosageRedirect($pidgin),
                QuestionIntent::DOSAGE,
                ContextBlock::empty(),
                redirectedToConsultation: true,
            );
        }

        $context = $this->assembler->assemble($this->withDefaultState($intent, $options));

        /*
         * The cache is only consulted on the first question of a thread.
         * "And for 1,000 of them?" is not a question that can be answered from
         * a store keyed on its own words — the meaning is in the turn before
         * it — and replaying a cached answer into a conversation is how a bot
         * ends up confidently answering something nobody asked.
         */
        $cacheable = $history === [];
        $normalised = $this->normalise($message);

        if ($cacheable) {
            $hit = $this->cache->get($normalised, $context);

            if ($hit !== null) {
                return ChatReply::cached($hit, $intent->type, $context);
            }
        }

        /*
         * Out of budget for the day. Not an error — the assistant says so
         * plainly and stays up. Somebody asking a common question a minute
         * later still gets a real answer out of the cache above, which is
         * exactly why the cache is checked first.
         */
        if (($options['cache_only'] ?? false) === true) {
            return ChatReply::written($this->busyMessage($pidgin), $intent->type, $context);
        }

        /*
         * Positional, not named. The provider is meant to be swappable, and a
         * named-argument call binds this line to the parameter NAMES in the
         * interface — an implementation that spells them differently fatals at
         * runtime with "unknown named parameter", which is a baffling error to
         * hit while writing a perfectly valid adapter.
         */
        $response = $this->provider->chat(
            $this->prompt->build($context, $this->situationNotes($intent, $context)),
            $this->window($history),
            $message,
        );

        if (! $response->ok) {
            return ChatReply::unavailable(
                $this->unavailableMessage($pidgin),
                $intent->type,
                $context,
                (string) $response->error,
            );
        }

        $text = trim($response->text);

        if ($cacheable) {
            $this->cache->put($normalised, $context, $text);
        }

        return ChatReply::fromProvider(
            text: $text,
            intent: $intent->type,
            context: $context,
            provider: $this->provider->name(),
            model: $this->provider->model(),
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
            latencyMs: $response->latencyMs,
        );
    }

    /**
     * The question flattened into a cache key and a grouping key.
     *
     * "How much feed for 500 Ross 308?" and "how much feed for 500 ross308"
     * are one question, and the whole value of the cache — on a free assistant
     * where the same twenty questions arrive all day — depends on treating them
     * as one. Punctuation, case and runs of whitespace go; digits stay, because
     * 500 birds and 5,000 birds are emphatically not the same question.
     */
    public function normalise(string $message): string
    {
        $text = mb_strtolower($this->tidy($message));
        $text = str_replace(',', '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::limit(trim($text), 240, '');
    }

    /**
     * The intent classifier only sees the message; the caller may know where
     * the person is from their profile, and a price question is better answered
     * locally than nationally when there is a choice.
     */
    private function withDefaultState(QuestionIntent $intent, array $options): QuestionIntent
    {
        $fallback = $options['state'] ?? null;

        if ($fallback === null || $intent->slot('state') !== null) {
            return $intent;
        }

        return QuestionIntent::of($intent->type, [...$intent->slots, 'state' => $fallback]);
    }

    /**
     * Things the model should know about this particular turn.
     *
     * Separate from the standing rules because they are about this question
     * rather than about the assistant, and mixing the two makes both easier to
     * ignore.
     *
     * @return array<int, string>
     */
    private function situationNotes(QuestionIntent $intent, ContextBlock $context): array
    {
        $notes = [];

        if (! $context->hasFigures) {
            $notes[] = __('No figures were retrieved for this question, so your answer must contain no figures at all.');
        }

        if ($intent->is(QuestionIntent::FEED) || $intent->is(QuestionIntent::PRICE)) {
            $notes[] = __('This is a question about a specific figure. If the figure is not above, say so and offer the paid consultation rather than approximating.');
        }

        if ($intent->slot('also_feed') === true) {
            $notes[] = __('The user is asking about cost and quantity together. Only combine them if both figures are above; never multiply one by the other yourself.');
        }

        return $notes;
    }

    /**
     * Drugs go to a person, always.
     *
     * Written by the platform rather than the model because "never give a
     * dosage" in a prompt is a request, and this is not a rule that can afford
     * to be a request. A wrong milligrams-per-litre kills a flock, and an
     * assistant that is confidently approximate about one is worse than one
     * that declines.
     */
    private function dosageRedirect(bool $pidgin): string
    {
        $book = url('/consult');

        if ($pidgin) {
            return __('I no fit give you drug name or dosage — na vet work be dat, and wrong dose fit finish your animals. Make you book consultation for :url, person wey sabi go look am proper. Wetin I fit help you with na the general side: housing, water, biosecurity, and how you go know say e don serious enough to call vet.', ['url' => $book]);
        }

        return __('I will not give drug names or dosages. A wrong dose can kill a flock, and getting it right needs a vet who has actually seen the animals — book a consultation at :url and the :company team will look at it properly. What I can help with is the general side: housing, water, biosecurity, isolating sick birds, and knowing when it has become a vet\'s job.', [
            'url' => $book,
            'company' => $this->branding->name(),
        ]);
    }

    /**
     * The day's budget is spent and this question was not in the cache.
     *
     * Says what is actually true. "Try again tomorrow" would be a lie by
     * omission — a common question asked a minute from now will be answered
     * from the cache — and pretending to be broken when the platform has
     * simply chosen a ceiling is not a good way to be trusted.
     */
    private function busyMessage(bool $pidgin): string
    {
        return $pidgin
            ? __('Plenty people don ask me question today, so I no fit work out this one now. Try again later, or book consultation for :url if e urgent.', ['url' => url('/consult')])
            : __('I have answered a great many questions today and cannot work through a new one right now. Common questions still answer instantly — try again later, or book a consultation at :url if it is urgent.', ['url' => url('/consult')]);
    }

    private function unavailableMessage(bool $pidgin): string
    {
        return $pidgin
            ? __('Sorry, I no fit answer right now — the assistant no dey reachable. Try again small time, or book consultation for :url if e urgent.', ['url' => url('/consult')])
            : __('Sorry — I cannot answer right now, the assistant is unreachable. Please try again in a moment, or book a consultation at :url if it is urgent.', ['url' => url('/consult')]);
    }

    /**
     * @param  array<int, AiMessage>  $history
     * @return array<int, AiMessage>
     */
    private function window(array $history): array
    {
        return array_slice(array_values($history), -self::HISTORY_TURNS);
    }

    private function tidy(string $message): string
    {
        // Control characters out: a prompt is a string somebody typed, and a
        // stray null or an ANSI escape has no business travelling to an API.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message) ?? $message;

        return Str::limit(trim($clean), self::MAX_MESSAGE_LENGTH, '');
    }
}
