<?php

namespace App\Mail;

use App\Models\Dispute;
use Illuminate\Mail\Mailables\Content;

class DisputeRaisedMail extends BrandedMailable
{
    public function __construct(
        public readonly Dispute $dispute,
        public readonly string $actionUrl = '/',
    ) {}

    /**
     * Unambiguous on purpose, and the reference is in the subject.
     *
     * This lands in an inbox beside forty other messages, and the person
     * reading it has a deadline to answer. "Update on your order" would be
     * read on Thursday. Naming the thing and the deadline in the subject is
     * the difference between a seller answering and a seller losing by
     * default.
     */
    protected function subjectLine(): string
    {
        // subjectReference() rather than the sub-order's: a mentorship
        // dispute has no order behind it, and naming one would print a dash
        // where the reference should be.
        return __('Action needed: dispute opened on :reference', [
            'reference' => $this->dispute->subjectReference(),
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.dispute-raised',
            with: [...$this->layoutData(), 'dispute' => $this->dispute, 'actionUrl' => $this->actionUrl],
        );
    }
}
