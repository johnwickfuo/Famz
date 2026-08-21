<?php

namespace App\Mail;

use App\Models\SubOrder;
use Illuminate\Mail\Mailables\Content;

/**
 * A seller has an order waiting.
 *
 * Its counterpart, OrderPaidMail, tells the buyer their money is safe. This one
 * is the reason the buyer eventually gets what they paid for: a seller who does
 * not know an order exists does not pack it.
 */
class SellerOrderReceivedMail extends BrandedMailable
{
    public function __construct(
        public readonly SubOrder $subOrder,
        public readonly string $actionUrl = '/',
    ) {}

    protected function subjectLine(): string
    {
        return __('New order to pack — :reference', [
            'reference' => $this->subOrder->reference,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.seller-order-received',
            with: [...$this->layoutData(), 'subOrder' => $this->subOrder, 'actionUrl' => $this->actionUrl],
        );
    }
}
