<?php

namespace App\Enums;

/**
 * A withdrawal from request to money-in-the-bank.
 *
 * `processing` is the state that matters: the transfer has been handed to the
 * gateway and nobody knows yet whether it worked. Money is already out of the
 * seller's available balance by then, so a failure has to put it back rather
 * than simply changing a label.
 */
enum WithdrawalStatus: string
{
    /** The seller has asked. Nothing has moved. */
    case Requested = 'requested';

    /** An administrator has agreed to it. */
    case Approved = 'approved';

    /** Handed to the gateway; awaiting its verdict. */
    case Processing = 'processing';

    /** The gateway says the money has landed. */
    case Paid = 'paid';

    /** The gateway could not send it. The balance goes back. */
    case Failed = 'failed';

    /** An administrator said no. The balance goes back. */
    case Rejected = 'rejected';

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
            self::Requested => __('Requested'),
            self::Approved => __('Approved'),
            self::Processing => __('On its way'),
            self::Paid => __('Paid'),
            self::Failed => __('Failed'),
            self::Rejected => __('Turned down'),
        };
    }

    /**
     * Whether this withdrawal is still holding money out of the balance.
     *
     * Everything except the two dead ends: a failed or rejected withdrawal has
     * given the money back, so it no longer reserves anything.
     */
    public function reservesFunds(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Processing, self::Paid], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Rejected], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
