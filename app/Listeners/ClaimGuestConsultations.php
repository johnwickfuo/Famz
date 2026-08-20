<?php

namespace App\Listeners;

use App\Services\Consultations\ConsultationService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Attach guest consultations to the account of whoever booked them.
 *
 * Somebody books at two in the morning from a phone, with no account, because
 * their birds are dying. They register the next day. Without this, the booking
 * they made is invisible to the account they just created, and they book again
 * — which the company then has to work out is the same problem.
 *
 * Matched on email, which is the only thing a guest booking and a new account
 * reliably share. On login as well as registration, because somebody may sign
 * in to an account that already existed.
 */
class ClaimGuestConsultations
{
    public function __construct(private readonly ConsultationService $consultations) {}

    public function handle(Login|Registered|Verified $event): void
    {
        try {
            $this->consultations->claimFor($event->user);
        } catch (Throwable $exception) {
            // Never worth failing a sign-in over. The consultation is still
            // reachable by its reference either way.
            Log::warning('Could not claim guest consultations.', [
                'user_id' => $event->user->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
