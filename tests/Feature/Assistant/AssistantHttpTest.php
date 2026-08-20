<?php

use App\Http\Controllers\AssistantController;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResponse;
use Database\Seeders\BreedStandardSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/**
 * The assistant as a browser meets it.
 *
 * The two assertions worth having here are the disclaimer arriving on every
 * render from the server, and one guest being unable to read another's thread.
 * Everything else is plumbing.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(BreedStandardSeeder::class);

    Cache::flush();

    app()->instance(AiProvider::class, new class implements AiProvider
    {
        public function chat(string $systemPrompt, array $history, string $message): AiResponse
        {
            return AiResponse::success('You will need about 1,526 kg of feed.', 800, 70, 610);
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
    });
});

it('is open to anybody, with no account', function (): void {
    get('/ask')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Assistant/Index')
            ->has('disclaimer.points', 4)
            ->has('suggestions'));
});

it('carries the disclaimer from the server on every render', function (): void {
    $response = get('/ask');

    $disclaimer = $response->viewData('page')['props']['disclaimer'];
    $text = implode(' ', $disclaimer['points']);

    // The three things the notice must say, per the module's own rules.
    expect($text)->toContain('general farming guidance')
        ->and($text)->toContain('published feeding tables')
        ->and($text)->toContain('will not give drug names')
        ->and($text)->toContain('book a paid consultation');
});

it('answers a question and stores both turns', function (): void {
    $response = postJson('/ask', ['message' => 'How much feed for 500 Ross 308 to week 6?'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('degraded', false);

    $answer = $response->json('answer');

    expect($answer['role'])->toBe('assistant')
        ->and($answer['content'])->toContain('1,526')
        // Sources travel with the answer, which is what turns a confident
        // sentence into a checkable one.
        ->and($answer['sources'][0])->toContain('Aviagen');
});

it('does not credit a source to an answer the model never wrote', function (): void {
    app()->instance(AiProvider::class, new \App\Services\Ai\NullAiProvider);

    $answer = postJson('/ask', ['message' => 'How much feed for 500 Ross 308 to week 6?'])
        ->assertOk()
        ->json('answer');

    /*
     * The context was assembled and stored — correctly, it is the record of
     * what was available. But the sentence on screen is the platform's own
     * apology, and captioning it "Figures from: Aviagen" would be exactly the
     * false confidence this feature exists to prevent.
     */
    expect($answer['content'])->toContain('cannot answer right now')
        ->and($answer['sources'])->toBe([]);
});

it('refuses an over-long message before it reaches the assistant', function (): void {
    postJson('/ask', ['message' => str_repeat('a', 1500)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});

it('answers a rate-limited request with a 429 and an explanation', function (): void {
    settings()->set('ai_guest_daily_messages', 1);

    postJson('/ask', ['message' => 'first question'])->assertOk();

    postJson('/ask', ['message' => 'second question'])
        ->assertStatus(429)
        ->assertJsonPath('ok', false)
        ->assertJsonStructure(['message', 'retry_after']);
});

it('will not let a guest read another guest\'s conversation', function (): void {
    $theirs = ChatConversation::factory()->create(['session_token' => 'somebody-else']);

    $mine = postJson('/ask', [
        'message' => 'a question of my own',
        'conversation_id' => $theirs->id,
    ])->assertOk()->json('conversation_id');

    // Quietly started a new thread instead of writing into theirs. The failure
    // mode of getting this wrong is reading somebody else's questions.
    expect($mine)->not->toBe($theirs->id)
        ->and($theirs->fresh()->messages()->count())->toBe(0);
});

it('will not let a signed-in user read a thread that is not theirs', function (): void {
    $other = User::factory()->create();
    $theirs = ChatConversation::factory()->forUser($other)->create();

    $me = User::factory()->create();

    $mine = $this->actingAs($me)
        ->postJson('/ask', ['message' => 'my question', 'conversation_id' => $theirs->id])
        ->assertOk()
        ->json('conversation_id');

    expect($mine)->not->toBe($theirs->id);
});

it('keeps a guest thread going across requests', function (): void {
    $first = postJson('/ask', ['message' => 'my first question'])->json('conversation_id');
    $second = postJson('/ask', ['message' => 'my second question', 'conversation_id' => $first])->json('conversation_id');

    expect($second)->toBe($first)
        ->and(ChatConversation::query()->find($first)->messages()->count())->toBe(4);
});

it('starts a fresh thread on request', function (): void {
    $first = postJson('/ask', ['message' => 'my first question'])->json('conversation_id');
    $fresh = postJson('/ask/reset')->assertOk()->json('conversation_id');

    expect($fresh)->not->toBe($first);
});

it('gives a signed-in user\'s thread no session token', function (): void {
    $user = User::factory()->create();

    $id = $this->actingAs($user)->postJson('/ask', ['message' => 'a question'])->json('conversation_id');

    $conversation = ChatConversation::query()->find($id);

    expect($conversation->user_id)->toBe($user->id)
        // A token left on an account's thread would let the next person on a
        // shared browser read it back as a guest.
        ->and($conversation->session_token)->toBeNull();
});

it('carries a guest thread onto an account on sign-in', function (): void {
    $id = postJson('/ask', ['message' => 'asked before signing up'])->json('conversation_id');
    $token = session(AssistantController::SESSION_KEY);

    expect($token)->not->toBeNull();

    $user = User::factory()->create();
    event(new \Illuminate\Auth\Events\Login('web', $user, false));

    expect(ChatConversation::query()->find($id)->user_id)->toBe($user->id)
        ->and(session(AssistantController::SESSION_KEY))->toBeNull();
});
