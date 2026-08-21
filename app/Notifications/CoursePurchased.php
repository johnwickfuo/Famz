<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\CoursePurchasedMail;
use App\Models\Enrolment;

class CoursePurchased extends PlatformNotification
{
    public function __construct(public readonly Enrolment $enrolment) {}

    public function mailable(object $notifiable): BrandedMailable
    {
        return new CoursePurchasedMail($this->enrolment, $this->url());
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Academy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'course.purchased',
            'enrolment_id' => $this->enrolment->getKey(),
            'title' => __(':course is open', ['course' => $this->enrolment->course?->title ?? __('Your course')]),
            'body' => __('Start whenever you like — it does not expire.'),
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return '/academy/'.($this->enrolment->course?->slug ?? '').'/learn';
    }
}
