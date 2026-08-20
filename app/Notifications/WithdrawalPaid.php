<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Mail\BrandedMailable;
use App\Mail\WithdrawalPaidMail;
use App\Enums\RoleName;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Money;

/**
 * Money has left the platform towards somebody's bank.
 *
 * Essential, and for two reasons rather than one. The obvious one is that
 * people watch for their money. The other is that an unexpected payout notice
 * is the first thing a seller sees if somebody has changed their payout
 * account — which is exactly the notification an attacker would want silenced.
 */
class WithdrawalPaid extends PlatformNotification
{
    public function __construct(public readonly Withdrawal $withdrawal) {}

    /**
     * Where this person's earnings actually live.
     *
     * A seller and a mentor both withdraw money and land in different panels,
     * so the link is resolved from the recipient rather than passed in by the
     * service — which does not know, and should not have to know, which panel
     * the person who is owed money signs into.
     */
    private function earningsUrl(object $notifiable): string
    {
        if ($notifiable instanceof User && $notifiable->hasRole(RoleName::Seller->value)) {
            return route('filament.seller.pages.earnings');
        }

        if ($notifiable instanceof User && $notifiable->hasRole(RoleName::Mentor->value)) {
            return route('filament.mentor.pages.dashboard');
        }

        return route('dashboard');
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Payouts;
    }

    public function mailable(object $notifiable): BrandedMailable
    {
        return new WithdrawalPaidMail($this->withdrawal, $this->earningsUrl($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'withdrawal.paid',
            'title' => __(':amount was paid to your bank account', [
                'amount' => Money::fromKobo($this->withdrawal->amount_kobo),
            ]),
            'body' => __('Reference :reference. It can take a few hours to show in your bank.', [
                'reference' => $this->withdrawal->reference,
            ]),
            'url' => $this->earningsUrl($notifiable),
        ];
    }
}
