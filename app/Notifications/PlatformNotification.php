<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Every notification the platform sends extends this.
 *
 * Two channels always: a database row so it can be read in the app, and an
 * email so it reaches somebody who is not looking at the app — which, for a
 * trader in a market, is most of the time.
 *
 * The mail half is an ordinary BrandedMailable rather than a hand-built
 * MailMessage, so notifications go through exactly the same branded layout,
 * sender name and placeholder expansion as everything else. There is one mail
 * path in this application and this is not an exception to it.
 *
 * Queued, always: a slow provider must never hold up the web request that
 * caused the notification.
 */
abstract class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * What kind of thing this is, for grouping and for preferences.
     *
     * Abstract rather than defaulted. A notification with no category would
     * silently become unfilterable — arriving whatever anybody had switched off
     * — and the failure would be invisible until somebody complained about
     * email they could not stop.
     */
    abstract public function category(): NotificationCategory;

    /**
     * The channels, after the recipient's preferences.
     *
     * A non-User notifiable — an on-demand address for a guest who booked a
     * consultation without registering — has no preferences to consult and no
     * database to store a row in, so it gets mail and nothing else.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return app(NotificationPreferences::class)->channelsFor($notifiable, $this->category());
    }

    abstract public function mailable(object $notifiable): BrandedMailable;

    /**
     * What the in-app list shows.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(object $notifiable): array;

    /**
     * The stored row, with the category stamped on by the base class.
     *
     * Written here rather than left to each subclass so that filtering the
     * notification centre by category cannot be defeated by one notification
     * forgetting to include it — and so an old row keeps the category it was
     * sent under even if the code later changes.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [...$this->toArray($notifiable), 'category' => $this->category()->value];
    }

    public function toMail(object $notifiable): BrandedMailable
    {
        /*
         * Addressed here, not left to the channel.
         *
         * Laravel's mail channel adds the recipient itself only for a
         * MailMessage; hand it a Mailable and it calls send() as-is, which
         * throws for want of a "To" header — as a queued job, silently. The
         * one line below is what makes returning a real Mailable safe.
         */
        $address = $notifiable->routeNotificationFor('mail', $this)
            ?? ($notifiable->email ?? null);

        $mailable = $this->mailable($notifiable);

        return $address === null ? $mailable : $mailable->to($address);
    }
}
