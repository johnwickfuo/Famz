<?php

namespace App\Enums;

enum LedgerType: string
{
    /** A seller's payout for goods sold, net of commission. */
    case Sale = 'sale';

    /** The platform's cut of a sale. */
    case Commission = 'commission';

    /** Money returned to a buyer. Recorded for audit; never spendable. */
    case Refund = 'refund';

    /** Money paid out of a wallet to a bank account. */
    case Withdrawal = 'withdrawal';

    /** A manual correction by an administrator. */
    case Adjustment = 'adjustment';

    /** A mentor's fee for a consultation, from a later phase. */
    case MentorshipEarning = 'mentorship_earning';

    /** Cancels an entry that had already been released. */
    case Reversal = 'reversal';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Sale => __('Sale'),
            self::Commission => __('Commission'),
            self::Refund => __('Refund'),
            self::Withdrawal => __('Withdrawal'),
            self::Adjustment => __('Adjustment'),
            self::MentorshipEarning => __('Mentorship'),
            self::Reversal => __('Reversal'),
        };
    }

    /**
     * The types that count as somebody having earned something, for lifetime
     * earnings.
     */
    public function isEarning(): bool
    {
        return in_array($this, [self::Sale, self::MentorshipEarning], true);
    }
}
