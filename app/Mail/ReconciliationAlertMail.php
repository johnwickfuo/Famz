<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;

class ReconciliationAlertMail extends BrandedMailable
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(public readonly array $result) {}

    /**
     * The count and the severity go in the subject.
     *
     * This lands beside every other notification an administrator gets. It has
     * to be distinguishable from them at a glance, on a phone, at night.
     */
    protected function subjectLine(): string
    {
        return $this->result['critical'] > 0
            ? __('URGENT: :count accounting discrepanc:plural found', [
                'count' => $this->result['critical'],
                'plural' => $this->result['critical'] === 1 ? 'y' : 'ies',
            ])
            : __('Reconciliation found :count thing:plural worth checking', [
                'count' => count($this->result['findings']),
                'plural' => count($this->result['findings']) === 1 ? '' : 's',
            ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.messages.reconciliation-alert',
            with: [...$this->layoutData(), 'result' => $this->result],
        );
    }
}
