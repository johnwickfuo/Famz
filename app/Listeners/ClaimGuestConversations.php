<?php

namespace App\Listeners;

use App\Services\Ai\Chat\ConversationService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * Carry a guest's conversation with the assistant onto their account.
 *
 * Most people who ask the assistant a first question have not registered. Some
 * of them register afterwards — often because the assistant was useful — and
 * the thread they were halfway through is the last thing that should vanish at
 * that moment.
 *
 * Matched on the session token rather than an email, because the assistant
 * never asks for one. That means it only works in the same browser session,
 * which is exactly the right scope: it is the same person at the same keyboard.
 */
class ClaimGuestConversations
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function handle(Login|Registered $event): void
    {
        try {
            $token = Session::get('ai_chat_token');

            if (blank($token)) {
                return;
            }

            $this->conversations->claimFor($event->user, (string) $token);

            // The token has done its job. Keeping it would leave the browser
            // holding a key to threads that now belong to an account.
            Session::forget('ai_chat_token');
        } catch (Throwable $exception) {
            // Never worth failing a sign-in over.
            Log::warning('Could not claim guest conversations.', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
