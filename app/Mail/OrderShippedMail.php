<?php

namespace App\Mail;

use App\Models\SubOrder;
use Illuminate\Mail\Mailables\Content;

/**
 * Something is on its way.
 */
class OrderShippedMail extends BrandedMailable
{
    public function __construct(
        public readonly SubOrder $subOrder,
        public readonly string $actionUrl = '/',
    ) {}

    protected function subjectLine(): string
    {
        return __('On its way — :reference', [
            'reference' => $this->subOrder->reference,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.order-shipped',
            with: [...$this->layoutData(), 'subOrder' => $this->subOrder, 'actionUrl' => $this->actionUrl],
        );
    }
}
