<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailables\Content;

/**
 * The receipt. The one email a buyer will look for again.
 */
class OrderPaidMail extends BrandedMailable
{
    public function __construct(
        public readonly Order $order,
        public readonly string $actionUrl = '/',
    ) {}

    /**
     * The reference goes in the subject.
     *
     * Somebody who has just parted with money searches their inbox for the
     * order number, not for the word "receipt" — and they search weeks later,
     * when something has gone wrong and they need to prove they paid.
     */
    protected function subjectLine(): string
    {
        return __('Payment received — order :reference', [
            'reference' => $this->order->reference,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.order-paid',
            with: [...$this->layoutData(), 'order' => $this->order, 'actionUrl' => $this->actionUrl],
        );
    }
}
