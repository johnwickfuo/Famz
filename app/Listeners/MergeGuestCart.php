<?php

namespace App\Listeners;

use App\Services\Cart\CartService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Move a guest's cart into their account when they sign in or register.
 *
 * A buyer who filled a cart, hit checkout and was asked to sign in should find
 * it still there afterwards — losing it at exactly that moment is how a sale is
 * lost.
 */
class MergeGuestCart
{
    public function __construct(private readonly CartService $cart) {}

    public function handle(Login|Registered $event): void
    {
        try {
            $this->cart->mergeGuestCartInto($event->user);
        } catch (Throwable $exception) {
            // A cart is never worth failing a sign-in over.
            Log::warning('Could not merge a guest cart on sign-in.', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
