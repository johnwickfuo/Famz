<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\EngagementConfirmedMail;
use App\Models\MentorshipEngagement;

class EngagementConfirmed extends PlatformNotification
{
    public function __construct(
        public readonly MentorshipEngagement $engagement,
        public readonly bool $forMentor = false,
    ) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new EngagementConfirmedMail($this->engagement, $this->forMentor, $this->url());
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Mentorship;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'engagement.confirmed',
            'engagement_id' => $this->engagement->getKey(),
            'title' => $this->forMentor
                ? __('You confirmed :reference', ['reference' => $this->engagement->reference])
                : __('Your mentor confirmed :reference', ['reference' => $this->engagement->reference]),
            // Contact details are withheld until this moment, so their becoming
            // visible is the actual news.
            'body' => __('Contact details are now visible to both of you.'),
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return ($this->forMentor ? '/mentor/engagements/' : '/mentorship/').$this->engagement->reference;
    }
}
