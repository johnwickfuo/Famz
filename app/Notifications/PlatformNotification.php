<?php

namespace App\Notifications;

use App\Mail\BrandedMailable;
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
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    abstract public function mailable(object $notifiable): BrandedMailable;

    /**
     * What the in-app list shows.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(object $notifiable): array;

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
