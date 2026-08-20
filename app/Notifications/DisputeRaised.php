<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\DisputeRaisedMail;
use App\Models\Dispute;

/**
 * Somebody has opened a dispute, and the other party has a clock running.
 *
 * Essential category: this arrives whether or not the recipient has switched
 * disputes off, because a seller who does not answer loses by default and
 * "I turned off emails" is not a defence anybody should have to make.
 */
class DisputeRaised extends PlatformNotification
{
    public function __construct(
        public readonly Dispute $dispute,
        public readonly string $actionUrl = '/',
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Disputes;
    }

    public function mailable(object $notifiable): BrandedMailable
    {
        return new DisputeRaisedMail($this->dispute, $this->actionUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'dispute.raised',
            'title' => __('A dispute was opened on :reference', [
                'reference' => $this->dispute->subjectReference(),
            ]),
            'body' => __('Answer it before the review window closes.'),
            'url' => $this->actionUrl,
        ];
    }
}
