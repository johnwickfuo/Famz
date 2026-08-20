<?php

namespace App\Mail;

use App\Models\Withdrawal;
use App\Support\Money;
use Illuminate\Mail\Mailables\Content;

class WithdrawalPaidMail extends BrandedMailable
{
    public function __construct(
        public readonly Withdrawal $withdrawal,
        public readonly string $actionUrl = '/',
    ) {}

    /**
     * The amount goes in the subject.
     *
     * Somebody who is owed money and is watching for it should be able to see
     * that it has gone without opening anything — and somebody who was NOT
     * expecting a payout should see an amount they do not recognise
     * immediately, because that is what a compromised payout account looks
     * like from the outside.
     */
    protected function subjectLine(): string
    {
        return __('Paid: :amount sent to your bank account', [
            'amount' => Money::fromKobo($this->withdrawal->amount_kobo),
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.withdrawal-paid',
            with: [...$this->layoutData(), 'withdrawal' => $this->withdrawal, 'actionUrl' => $this->actionUrl],
        );
    }
}
