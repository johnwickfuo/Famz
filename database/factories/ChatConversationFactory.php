<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'session_token' => (string) Str::uuid(),
            'title' => null,
            'last_message_at' => now(),
        ];
    }

    /**
     * A thread belonging to an account rather than a browser session.
     */
    public function forUser(int|\App\Models\User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user instanceof \App\Models\User ? $user->getKey() : $user,
            'session_token' => null,
        ]);
    }
}
