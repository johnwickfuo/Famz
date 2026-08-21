<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\ReconciliationAlertMail;

/**
 * The books do not agree, and somebody needs to look tonight.
 *
 * Filed under Payouts, which is an essential category — this cannot be switched
 * off. An administrator who has muted platform email is still the person who
 * has to know that money is unaccounted for.
 */
class ReconciliationAlert extends PlatformNotification
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(public readonly array $result) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Payouts;
    }

    public function mailable(object $notifiable): BrandedMailable
    {
        return new ReconciliationAlertMail($this->result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = count($this->result['findings']);

        return [
            'kind' => 'reconciliation.alert',
            'title' => trans_choice(
                '{1} The books do not agree: 1 discrepancy|[2,*] The books do not agree: :count discrepancies',
                $count,
                ['count' => $count],
            ),
            'body' => __(':critical of them are critical.', ['critical' => $this->result['critical']]),
            'url' => route('filament.admin.pages.reconciliation'),
        ];
    }
}
