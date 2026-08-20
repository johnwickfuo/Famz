<?php

namespace App\Services\Ai\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Ai\AiMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The assistant with a memory and a bill attached.
 *
 * ChatService answers questions. This decides whether somebody is allowed to
 * ask one, finds or starts their thread, stores both turns, records what was
 * spent, and hands back something a controller can render. Keeping the two
 * apart means "what does the assistant say" can be tested without a session, a
 * user, a rate limiter or a database, which is most of what makes the answer
 * quality testable at all.
 */
class ConversationService
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatGuard $guard,
    ) {}

    /**
     * Ask a question and keep the result.
     *
     * @param  array<string, mixed>  $options
     */
    public function ask(
        string $message,
        ?User $user,
        ?string $sessionToken,
        string $ip,
        ?ChatConversation $conversation = null,
        array $options = [],
    ): ChatTurn {
        $decision = $this->guard->check($user, $sessionToken, $ip);

        if (! $decision->allowed) {
            // Nothing is stored and nothing is charged. A refusal is not a
            // conversation, and writing it into somebody's thread would leave
            // them scrolling past their own rate limiting forever.
            return ChatTurn::blocked((string) $decision->reason, $decision->retryAfterSeconds);
        }

        $conversation = $conversation ?? $this->start($user, $sessionToken);

        $history = $this->historyFor($conversation);

        $reply = $this->chat->answer($message, $history, [
            ...$options,
            'cache_only' => $decision->cacheOnly,
        ]);

        $question = $conversation->messages()->create([
            'role' => ChatMessage::ROLE_USER,
            'content' => $message,
            'normalised_question' => $this->chat->normalise($message),
        ]);

        $answer = $conversation->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => $reply->text,
            // The receipt. Stored on every answer, including the ones the
            // platform wrote itself, so an empty context is recorded as an
            // empty context rather than as a missing column.
            'context_used' => $reply->context->toArray(),
            'prompt_tokens' => $reply->promptTokens,
            'completion_tokens' => $reply->completionTokens,
            'tokens_used' => $reply->totalTokens(),
            'latency_ms' => $reply->latencyMs,
            'from_cache' => $reply->fromCache,
            'provider' => $reply->provider,
            'model' => $reply->model,
            'normalised_question' => $question->normalised_question,
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'title' => $conversation->title ?: Str::limit($message, 60),
        ])->save();

        /*
         * Charged only for answers that were actually produced. A cached reply
         * costs the platform nothing, so it costs the day's budget nothing —
         * which is what makes cache-only mode a real degradation rather than a
         * slower way of running out.
         */
        $this->guard->recordMessage($user, $sessionToken, $ip);
        $this->guard->recordSpend($reply->totalTokens());

        $this->log($reply, $user, $conversation);

        return ChatTurn::answered($conversation, $question, $answer, $reply, $decision->cacheOnly);
    }

    public function start(?User $user, ?string $sessionToken): ChatConversation
    {
        return ChatConversation::query()->create([
            'user_id' => $user?->getKey(),
            // A signed-in user's thread carries no token: it belongs to the
            // account, and leaving a stale one on it would let a shared browser
            // read it back later as a guest.
            'session_token' => $user !== null ? null : $sessionToken,
            'last_message_at' => now(),
        ]);
    }

    /**
     * The most recent thread for whoever is asking, or a new one.
     */
    public function current(?User $user, ?string $sessionToken): ChatConversation
    {
        $existing = ChatConversation::query()
            ->ownedBy($user, $sessionToken)
            ->latest('last_message_at')
            ->first();

        return $existing ?? $this->start($user, $sessionToken);
    }

    /**
     * Attach a guest's threads to the account they have just made.
     *
     * Somebody asks three questions at eleven at night, decides the platform is
     * worth signing up to, and registers. Losing the conversation at that exact
     * moment would be the platform punishing them for it.
     */
    public function claimFor(User $user, ?string $sessionToken): int
    {
        if (blank($sessionToken)) {
            return 0;
        }

        return ChatConversation::query()
            ->whereNull('user_id')
            ->where('session_token', $sessionToken)
            ->update([
                'user_id' => $user->getKey(),
                // The token goes with the claim. Leaving it would mean the
                // thread stayed readable by anyone holding that session.
                'session_token' => null,
            ]);
    }

    /**
     * The turns that go back to the model, oldest first.
     *
     * @return array<int, AiMessage>
     */
    public function historyFor(ChatConversation $conversation): array
    {
        return $conversation->messages()
            ->whereIn('role', [ChatMessage::ROLE_USER, ChatMessage::ROLE_ASSISTANT])
            ->latest('id')
            ->limit(ChatService::HISTORY_TURNS)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $message): AiMessage => new AiMessage(
                $message->role,
                (string) $message->content,
            ))
            ->values()
            ->all();
    }

    /**
     * One line per call, so spend is visible without querying the database.
     */
    private function log(ChatReply $reply, ?User $user, ChatConversation $conversation): void
    {
        Log::info('Assistant answered.', [
            'conversation_id' => $conversation->getKey(),
            'user_id' => $user?->getKey(),
            'intent' => $reply->intent,
            'had_figures' => $reply->context->hasFigures,
            'from_cache' => $reply->fromCache,
            'redirected' => $reply->redirectedToConsultation,
            'provider' => $reply->provider,
            'tokens' => $reply->totalTokens(),
            'latency_ms' => $reply->latencyMs,
            'ok' => $reply->ok,
            'budget_remaining' => $this->guard->budgetRemaining(),
        ]);
    }
}
