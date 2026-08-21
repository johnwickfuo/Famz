<?php

namespace App\Listeners;

use App\Services\Mail\SuppressionList;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * The half of bounce handling that actually saves anything.
 *
 * Recording a bounce is bookkeeping. This is the part that stops the platform
 * writing to the address again — and it sits at the last possible moment,
 * on the message itself, so it catches every route to the mailer: a Mailable,
 * a notification, a queued job retried three days later, and whatever gets
 * added next without anybody remembering this exists.
 *
 * Returning false from a MessageSending listener cancels the send.
 */
class StopMailToSuppressedAddresses
{
    public function __construct(private readonly SuppressionList $suppressions) {}

    public function handle(MessageSending $event): bool
    {
        $recipients = $event->message->getTo();

        if ($recipients === []) {
            return true;
        }

        foreach ($recipients as $address) {
            if (! $this->suppressions->isSuppressed($address->getAddress())) {
                // One live recipient is enough. Cancelling a message because
                // one of three addresses is dead would punish the other two.
                return true;
            }
        }

        /*
         * Logged rather than dropped silently. "Why did that person not get
         * their receipt" is a question somebody will ask, and the answer needs
         * to exist somewhere.
         */
        Log::info('Mail suppressed: every recipient is on the suppression list.', [
            'to' => array_map(fn ($address): string => $address->getAddress(), $recipients),
            'subject' => $event->message->getSubject(),
        ]);

        return false;
    }
}
