<?php

namespace App\Mail;

use App\Models\Enrolment;
use Illuminate\Mail\Mailables\Content;

/**
 * A course is open. Contains the link to start it.
 *
 * The one thing this email must do is get somebody into the first lesson.
 * Course sales are final, so a buyer who pays and then cannot find what they
 * bought has no way out except asking for a refund they will not get.
 */
class CoursePurchasedMail extends BrandedMailable
{
    public function __construct(
        public readonly Enrolment $enrolment,
        public readonly string $actionUrl = '/',
    ) {}

    protected function subjectLine(): string
    {
        return __('You can start :course now', [
            'course' => $this->enrolment->course?->title ?? __('your course'),
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.course-purchased',
            with: [...$this->layoutData(), 'enrolment' => $this->enrolment, 'actionUrl' => $this->actionUrl],
        );
    }
}
