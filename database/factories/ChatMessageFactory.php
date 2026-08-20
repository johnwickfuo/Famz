<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    public function definition(): array
    {
        return [
            'chat_conversation_id' => ChatConversation::factory(),
            'role' => ChatMessage::ROLE_USER,
            'content' => 'How much feed for 500 Ross 308 to week 6?',
            'context_used' => null,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'tokens_used' => 0,
            'latency_ms' => 0,
            'from_cache' => false,
            'provider' => null,
            'model' => null,
            'normalised_question' => 'how much feed for 500 ross 308 to week 6',
        ];
    }

    public function assistant(string $content = 'You will need about 1,526 kg.'): static
    {
        return $this->state(fn (): array => [
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => $content,
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'prompt_tokens' => 900,
            'completion_tokens' => 120,
            'tokens_used' => 1020,
            'latency_ms' => 850,
        ]);
    }
}
