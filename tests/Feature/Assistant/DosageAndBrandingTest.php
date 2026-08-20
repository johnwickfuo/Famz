<?php

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResponse;
use App\Services\Ai\Chat\ChatService;
use App\Services\Ai\Chat\IntentClassifier;
use App\Services\Ai\Chat\QuestionIntent;
use App\Services\Branding\BrandingKey;
use App\Services\Branding\BrandingService;
use Database\Seeders\BreedStandardSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * The two rules that cannot be left to a prompt.
 *
 * Drug questions go to a person, and the company name comes from settings. Both
 * are enforced in PHP rather than requested in the system prompt, because a
 * prompt is a request and neither of these can afford to be one.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->seed(BreedStandardSeeder::class);

    Cache::flush();

    $this->provider = new class implements AiProvider
    {
        public int $calls = 0;

        public string $systemPrompt = '';

        public function chat(string $systemPrompt, array $history, string $message): AiResponse
        {
            $this->calls++;
            $this->systemPrompt = $systemPrompt;

            return AiResponse::success('Give 5 ml per litre for three days.', 100, 20, 200);
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

    app()->instance(AiProvider::class, $this->provider);

    $this->chat = app(ChatService::class);
});

it('redirects a drug question without asking a model at all', function (string $question): void {
    $reply = $this->chat->answer($question);

    expect($reply->intent)->toBe(QuestionIntent::DOSAGE)
        ->and($reply->redirectedToConsultation)->toBeTrue()
        // The stub above would happily have said "5 ml per litre". It was never
        // asked, which is the point: there is no prompt to argue with and no
        // dosage that can slip out.
        ->and($this->provider->calls)->toBe(0)
        ->and($reply->text)->not->toContain('ml per litre')
        ->and($reply->text)->toContain('consultation');
})->with([
    'a direct dosage question' => 'What dosage of amprolium for 200 broilers?',
    'a millilitre question' => 'How many ml of tylosin per litre of water?',
    'naming an antibiotic' => 'Which antibiotic is best for CRD in layers?',
    'asking to prescribe' => 'Can you prescribe something for my sick birds?',
    'in Pidgin' => 'Wetin I go give am for coccidiosis?',
    'asking which drug' => 'Which drug I fit use for my chicken wey dey cough?',
    'a vaccination volume' => 'What vaccine dose for day old chicks?',
]);

it('answers the redirect in the language the question was asked in', function (): void {
    $english = $this->chat->answer('What dosage of amprolium should I give?');
    $pidgin = $this->chat->answer('Wetin I go give am for coccidiosis?');

    expect($english->text)->toContain('I will not give drug names')
        ->and($pidgin->text)->toContain('I no fit give you drug name');
});

it('lets an ordinary husbandry question through', function (): void {
    // Symptoms, biosecurity and when to call a vet are not prescriptions, and
    // an assistant that refused these would be useless to a farmer with a
    // problem at six in the morning.
    $reply = $this->chat->answer('My birds are coughing and the litter is damp. What should I check?');

    expect($reply->intent)->not->toBe(QuestionIntent::DOSAGE)
        ->and($this->provider->calls)->toBe(1);
});

it('still forbids dosages in the prompt, for anything the classifier misses', function (): void {
    $this->chat->answer('What should I check before putting chicks in the brooder?');

    expect($this->provider->systemPrompt)
        ->toContain('never give a drug dosage')
        ->toContain('a real vet has to see the birds');
});

it('introduces itself with whatever name the administrator has set', function (): void {
    settings()->set(BrandingKey::Name->value, 'Olusegun Agro Services');
    Cache::forget(BrandingService::CACHE_KEY);

    // A fresh service: the name is read at call time, not at boot.
    app()->forgetInstance(BrandingService::class);
    app()->forgetInstance(ChatService::class);

    app(ChatService::class)->answer('Who are you?');

    expect($this->provider->systemPrompt)
        ->toContain('Olusegun Agro Services')
        // And the rule that stops it reaching for a name of its own.
        ->toContain('NEVER state, guess or imply any other company');
});

it('names the company in the dosage redirect too', function (): void {
    settings()->set(BrandingKey::Name->value, 'Olusegun Agro Services');
    Cache::forget(BrandingService::CACHE_KEY);
    app()->forgetInstance(BrandingService::class);
    app()->forgetInstance(ChatService::class);

    expect(app(ChatService::class)->answer('What dosage of tylosin?')->text)
        ->toContain('Olusegun Agro Services');
});

it('hard-codes no company name anywhere in the assistant', function (): void {
    /*
     * The standing rule of this codebase, checked here for the module most
     * likely to break it. A model will invent an organisation given half a
     * chance, and a developer writing an example prompt is just as likely to
     * paste one in.
     */
    $files = collect(\Illuminate\Support\Facades\File::allFiles(app_path('Services/Ai')))
        ->merge(\Illuminate\Support\Facades\File::allFiles(app_path('Http/Controllers')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php');

    foreach ($files as $file) {
        expect(mb_strtolower($file->getContents()))
            ->not->toContain('agri platform', $file->getFilename())
            ->not->toContain('agriplatform', $file->getFilename());
    }
});

it('recognises Pidgin phrasings of the questions people actually ask', function (string $question, string $expected): void {
    expect(app(IntentClassifier::class)->classify($question)->type)->toBe($expected);
})->with([
    ['How much feed my 200 broiler go chop for week 3?', QuestionIntent::FEED],
    ['Na how much be day old broiler chicks?', QuestionIntent::PRICE],
    ['Wetin I go give am for worm?', QuestionIntent::DOSAGE],
    ['My goat no dey chop, wetin fit cause am?', QuestionIntent::FEED],
]);

it('tells the model to answer in the language it was written in', function (): void {
    $this->chat->answer('Abeg how I go take start poultry?');

    expect($this->provider->systemPrompt)
        ->toContain('Reply in the same language the user wrote in')
        ->toContain('Nigerian Pidgin');
});

it('degrades honestly when no provider is configured at all', function (): void {
    app()->instance(AiProvider::class, new \App\Services\Ai\NullAiProvider);
    app()->forgetInstance(ChatService::class);

    $reply = app(ChatService::class)->answer('How much feed for 500 Ross 308 to week 6?');

    expect($reply->ok)->toBeFalse()
        // Still a readable sentence on somebody's screen, never a stack trace
        // and never an empty string.
        ->and($reply->text)->toContain('cannot answer right now')
        // And the context was still assembled and is still on the record.
        ->and($reply->context->hasFigures)->toBeTrue();
});
