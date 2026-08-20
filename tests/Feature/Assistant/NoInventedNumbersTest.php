<?php

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResponse;
use App\Services\Ai\Chat\ChatService;
use App\Services\Ai\Chat\ContextBlock;
use App\Services\Ai\Chat\QuestionIntent;
use Database\Seeders\BreedStandardSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * The rule the whole feature is built around.
 *
 * The assistant may not produce a figure that was not handed to it. Every other
 * test in this directory is about a mechanism; this one is about the outcome,
 * and it is written the way an auditor would check it — take the answer, pull
 * every number out of it, and prove each one was in the context block.
 *
 * The provider is stubbed rather than called. That is the point: a real model
 * would pass or fail this on any given day depending on its mood, and what is
 * being tested here is the platform's machinery — that the context is
 * assembled, that the audit can be run against it, and that a question with no
 * data behind it produces a block that forbids figures outright.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->seed(BreedStandardSeeder::class);

    /**
     * Pull every number out of a piece of prose, normalised the way the
     * context block normalises its own.
     *
     * @return array<int, string>
     */
    $this->numbersIn = function (string $text): array {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $n): string => ContextBlock::normaliseNumber($n))
            ->unique()
            ->values()
            ->all();
    };

    /**
     * Stub the provider with a fixed answer, and capture the prompt it saw.
     */
    $this->respondWith = function (string $answer): object {
        $spy = new class($answer) implements AiProvider
        {
            public string $systemPrompt = '';

            /** @var array<int, string> */
            public array $userMessages = [];

            public function __construct(private readonly string $answer) {}

            public function chat(string $systemPrompt, array $history, string $message): AiResponse
            {
                $this->systemPrompt = $systemPrompt;
                $this->userMessages[] = $message;

                return AiResponse::success($this->answer, 800, 90, 640);
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'stub';
            }

            public function model(): string
            {
                return 'stub-1';
            }
        };

        app()->instance(AiProvider::class, $spy);

        return $spy;
    };
});

it('permits every figure a well-behaved answer states', function (): void {
    // What the model is pretending to say. Every number in it comes from the
    // seeded Ross 308 table by way of the calculator.
    ($this->respondWith)(
        'For 500 Ross 308 to week 6 you need about 1,526 kg of feed — that is 61.1 bags of 25 kg. '
        .'Allow 3,052 litres of water over the same period. They should reach around 3,010 g each.'
    );

    $reply = app(ChatService::class)->answer('How much feed for 500 Ross 308 to week 6?');

    $permitted = $reply->context->numbers();
    $stated = ($this->numbersIn)($reply->text);

    expect($reply->context->hasFigures)->toBeTrue()
        ->and(array_diff($stated, $permitted))->toBe([]);
});

it('catches an answer that states a figure it was never given', function (): void {
    /*
     * The failure this whole design exists to prevent, staged deliberately. The
     * feed figures are real; the price is invented, exactly the way a model
     * asked a question with an obvious plausible answer would invent one.
     */
    ($this->respondWith)(
        'For 500 Ross 308 to week 6 you need about 1,526 kg of feed. '
        .'A bag of starter costs around ₦18,500 at the moment.'
    );

    $reply = app(ChatService::class)->answer('How much feed for 500 Ross 308 to week 6?');

    $unbacked = array_diff(($this->numbersIn)($reply->text), $reply->context->numbers());

    // The audit must notice. If this ever comes back empty the audit has
    // stopped working, and every other assertion in this file is worthless.
    expect($unbacked)->toContain('18500');
});

it('forbids figures outright when nothing was retrieved', function (): void {
    $spy = ($this->respondWith)('Keep the brooder warm and check the litter every morning.');

    $reply = app(ChatService::class)->answer('My birds dey cough. Wetin I go do?');

    expect($reply->context->hasFigures)->toBeFalse()
        ->and($reply->context->numbers())->toBe([])
        ->and($spy->systemPrompt)->toContain('RETRIEVED DATA: none.')
        ->and($spy->systemPrompt)->toContain('Do not state any number');
});

it('tells the model plainly which figures it may use', function (): void {
    $spy = ($this->respondWith)('About 1,526 kg.');

    app(ChatService::class)->answer('How much feed for 500 Ross 308 to week 6?');

    expect($spy->systemPrompt)
        ->toContain('these are the ONLY figures you may use')
        ->toContain('1,526 kg')
        ->toContain('Aviagen Ross 308 broiler management guide')
        // The arithmetic ban matters as much as the recall ban: a model that
        // scales a per-bird figure to a flock is inventing a number by a
        // slightly more respectable route.
        ->toContain('Do NOT do arithmetic on the figures');
});

it('says it has no data rather than producing a number for a breed it does not hold', function (): void {
    $spy = ($this->respondWith)('I do not have a feeding table for that bird.');

    $reply = app(ChatService::class)->answer('How much feed for 300 Hubbard Flex to week 5?');

    expect($reply->context->hasFigures)->toBeFalse()
        ->and($spy->systemPrompt)->toContain('Ask which one before giving any feed figure')
        // And it is told what it does have, so it can ask a useful question
        // instead of a vague one.
        ->and($spy->systemPrompt)->toContain('Ross 308');
});

it('records the exact figures on the answer for later audit', function (): void {
    ($this->respondWith)('About 1,526 kg, or 61.1 bags.');

    $reply = app(ChatService::class)->answer('How much feed for 500 Ross 308 to week 6?');
    $stored = $reply->context->toArray();

    expect($stored['has_figures'])->toBeTrue()
        ->and($stored['numbers'])->toContain('1526')
        ->and($stored['facts'][0]['source'])->toContain('Aviagen')
        ->and($reply->intent)->toBe(QuestionIntent::FEED);
});
