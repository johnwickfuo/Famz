<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn, and — on an assistant turn — the receipt for it.
 *
 * `context_used` is the reason this table is worth the space. Every figure the
 * model was handed is stored verbatim alongside the answer it produced, with
 * the source of each. When somebody says in six months that the assistant told
 * them a bag of starter was eighteen thousand naira, this row is the answer:
 * either the figure is in the context with a date and a sample size, or it is
 * not and the model went outside its brief, and both of those are things worth
 * being able to establish rather than argue about.
 */
#[Fillable([
    'chat_conversation_id', 'role', 'content', 'context_used',
    'prompt_tokens', 'completion_tokens', 'tokens_used', 'latency_ms',
    'from_cache', 'provider', 'model', 'normalised_question',
])]
class ChatMessage extends Model
{
    use HasFactory;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected function casts(): array
    {
        return [
            'context_used' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'tokens_used' => 'integer',
            'latency_ms' => 'integer',
            'from_cache' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function isFromUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /**
     * The figures this answer was allowed to contain.
     *
     * @return array<int, string>
     */
    public function permittedNumbers(): array
    {
        return (array) data_get($this->context_used, 'numbers', []);
    }

    public function hadFigures(): bool
    {
        return (bool) data_get($this->context_used, 'has_figures', false);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAssistant(Builder $query): void
    {
        $query->where('role', self::ROLE_ASSISTANT);
    }
}
