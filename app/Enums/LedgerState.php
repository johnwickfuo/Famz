<?php

namespace App\Enums;

/**
 * Where an entry sits, which is what decides whether it counts toward a
 * balance. Balances are always derived by summing entries — there is no stored
 * balance column anywhere in this application — so these states are the whole
 * of the arithmetic.
 */
enum LedgerState: string
{
    /** Recorded but not yet counted anywhere. */
    case Pending = 'pending';

    /** In escrow: owed to the seller but not theirs to spend yet. */
    case Held = 'held';

    /** Spendable. */
    case Released = 'released';

    /** Cancelled. Kept for audit, counted toward nothing. */
    case Refunded = 'refunded';

    /** Paid out. Still counted, so the balance stays reduced. */
    case Withdrawn = 'withdrawn';

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
            self::Pending => __('Pending'),
            self::Held => __('Held in escrow'),
            self::Released => __('Available'),
            self::Refunded => __('Refunded'),
            self::Withdrawn => __('Withdrawn'),
        };
    }

    /**
     * The states that make up an available balance.
     *
     * Withdrawn counts as well as released: a withdrawal is a negative entry,
     * and it has to keep reducing the balance after it has been paid out.
     *
     * @return array<int, self>
     */
    public static function spendable(): array
    {
        return [self::Released, self::Withdrawn];
    }
}
