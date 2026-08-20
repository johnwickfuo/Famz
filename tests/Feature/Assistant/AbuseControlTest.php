<?php

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResponse;
use App\Services\Ai\Chat\ChatGuard;
use App\Services\Ai\Chat\ConversationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\BreedStandardSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * Keeping a free, open assistant from becoming somebody else's free API.
 *
 * The limits here are the boring half of the feature and the half that decides
 * whether it survives contact with the internet. The interesting assertion is
 * the last group: running out of money must degrade, not break.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(BreedStandardSeeder::class);

    Cache::flush();

    $this->answers = 0;

    $provider = new class implements AiProvider
    {
        public int $calls = 0;

        public function chat(string $systemPrompt, array $history, string $message): AiResponse
        {
            $this->calls++;

            return AiResponse::success('Keep the brooder at the right temperature.', 700, 60, 500);
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

    app()->instance(AiProvider::class, $provider);
    $this->provider = $provider;

    $this->conversations = app(ConversationService::class);
    $this->guard = app(ChatGuard::class);
});

it('cuts a guest off at their daily allowance', function (): void {
    settings()->set('ai_guest_daily_messages', 3);

    for ($i = 1; $i <= 3; $i++) {
        expect($this->conversations->ask("question {$i} about brooding", null, 'token-a', '10.0.0.1')->answered)
            ->toBeTrue("question {$i} should have been answered");
    }

    $blocked = $this->conversations->ask('a fourth question about brooding', null, 'token-a', '10.0.0.1');

    expect($blocked->answered)->toBeFalse()
        ->and($blocked->blockedReason)->toContain('3')
        ->and($blocked->retryAfterSeconds)->toBeGreaterThan(0);
});

it('gives an account a larger allowance than a guest', function (): void {
    settings()->set('ai_guest_daily_messages', 2);
    settings()->set('ai_user_daily_messages', 5);

    $user = User::factory()->create();

    for ($i = 1; $i <= 5; $i++) {
        expect($this->conversations->ask("question {$i}", $user, null, '10.0.0.2')->answered)->toBeTrue();
    }

    expect($this->conversations->ask('one more', $user, null, '10.0.0.2')->answered)->toBeFalse();
});

it('limits a connection regardless of how many session tokens it invents', function (): void {
    settings()->set('ai_ip_hourly_messages', 4);
    settings()->set('ai_guest_daily_messages', 100);

    // A script clearing its cookies between requests. The token changes every
    // time; the address does not, which is the whole point of this limit.
    for ($i = 1; $i <= 4; $i++) {
        expect($this->conversations->ask("question {$i}", null, "token-{$i}", '10.0.0.3')->answered)->toBeTrue();
    }

    expect($this->conversations->ask('another', null, 'token-fresh', '10.0.0.3')->answered)->toBeFalse();
});

it('does not charge a refused message against the allowance', function (): void {
    settings()->set('ai_guest_daily_messages', 1);

    $this->conversations->ask('first question', null, 'token-b', '10.0.0.4');

    // Three refused retries. Somebody who hits a limit must not be able to dig
    // themselves deeper into it by pressing the button again.
    foreach (range(1, 3) as $ignored) {
        $this->conversations->ask('another question', null, 'token-b', '10.0.0.4');
    }

    expect($this->guard->remainingFor(null, 'token-b', '10.0.0.4')['remaining'])->toBe(0)
        ->and($this->provider->calls)->toBe(1);
});

it('stores nothing for a refused message', function (): void {
    settings()->set('ai_guest_daily_messages', 1);

    $this->conversations->ask('first question', null, 'token-c', '10.0.0.5');
    $before = ChatMessage::query()->count();

    $this->conversations->ask('a refused question', null, 'token-c', '10.0.0.5');

    // A refusal is not a conversation, and writing it into somebody's thread
    // would leave them scrolling past their own rate limiting forever.
    expect(ChatMessage::query()->count())->toBe($before)
        ->and(ChatMessage::query()->where('content', 'a refused question')->exists())->toBeFalse();
});

it('degrades to cached answers rather than erroring when the budget is gone', function (): void {
    settings()->set('ai_daily_token_budget', 100000);

    // Prime the cache with one real answer.
    $first = $this->conversations->ask('how do I set up a brooder', null, 'token-d', '10.0.0.6');
    expect($first->answered)->toBeTrue()
        ->and($first->degraded)->toBeFalse();

    $this->guard->recordSpend(200_000);
    expect($this->guard->overBudget())->toBeTrue();

    $callsBefore = $this->provider->calls;

    // The cached question still answers, properly, for free.
    $cached = $this->conversations->ask('how do I set up a brooder', null, 'token-d', '10.0.0.6');

    expect($cached->answered)->toBeTrue()
        ->and($cached->reply->fromCache)->toBeTrue()
        ->and($cached->reply->text)->toBe($first->reply->text)
        ->and($cached->answer->tokens_used)->toBe(0)
        ->and($this->provider->calls)->toBe($callsBefore);

    // An uncached one gets an honest explanation, not an error.
    $busy = $this->conversations->ask('what temperature for week three chicks', null, 'token-d', '10.0.0.6');

    expect($busy->answered)->toBeTrue()
        ->and($busy->degraded)->toBeTrue()
        ->and($busy->answer->content)->toContain('great many questions')
        ->and($this->provider->calls)->toBe($callsBefore);
});

it('treats a budget of zero as no ceiling rather than no service', function (): void {
    settings()->set('ai_daily_token_budget', 0);
    $this->guard->recordSpend(5_000_000);

    expect($this->guard->overBudget())->toBeFalse()
        ->and($this->conversations->ask('a question', null, 'token-e', '10.0.0.7')->degraded)->toBeFalse();
});

it('spends nothing from the budget on a cached answer', function (): void {
    $this->conversations->ask('how do I set up a brooder', null, 'token-f', '10.0.0.8');
    $afterFirst = $this->guard->spentToday();

    $this->conversations->ask('How do I set up a brooder?', null, 'token-f', '10.0.0.8');

    expect($this->guard->spentToday())->toBe($afterFirst);
});

it('carries a guest thread onto the account they register with', function (): void {
    $turn = $this->conversations->ask('a guest question', null, 'token-g', '10.0.0.9');
    $user = User::factory()->create();

    $claimed = $this->conversations->claimFor($user, 'token-g');

    expect($claimed)->toBe(1)
        ->and($turn->conversation->fresh()->user_id)->toBe($user->id)
        // The token goes with the claim. Leaving it would let anyone holding
        // that session keep reading the thread.
        ->and($turn->conversation->fresh()->session_token)->toBeNull();
});

it('keeps one guest out of another guest\'s conversation', function (): void {
    $mine = ChatConversation::factory()->create(['session_token' => 'mine']);

    expect(ChatConversation::query()->whereKey($mine->id)->ownedBy(null, 'theirs')->exists())->toBeFalse()
        ->and(ChatConversation::query()->whereKey($mine->id)->ownedBy(null, 'mine')->exists())->toBeTrue()
        // A blank token must match nothing, not every guest thread on the site.
        ->and(ChatConversation::query()->ownedBy(null, null)->exists())->toBeFalse();
});

it('caps an over-long message rather than sending a paste to the provider', function (): void {
    $turn = $this->conversations->ask(str_repeat('feed ', 900), null, 'token-h', '10.0.0.10');

    expect(mb_strlen($turn->question->content))->toBeLessThanOrEqual(1000);
});
