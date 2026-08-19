<?php

namespace App\Contracts;

use App\Models\SubOrder;
use App\Models\User;

/**
 * How and when a seller's money becomes theirs.
 *
 * Two implementations ship: escrow, which holds the payout until the buyer has
 * the goods, and instant, which releases on payment. Which one is in force is a
 * platform setting, not a deploy.
 */
interface SettlementDriver
{
    /**
     * The key this driver is selected by in settings.
     */
    public function key(): string;

    /**
     * Called once, when a payment has been verified.
     *
     * Writes the seller's payout and the platform's commission to the ledger in
     * whatever state this driver considers correct.
     */
    public function recordSale(SubOrder $subOrder): void;

    /**
     * Called when the goods are confirmed with the buyer, or when the
     * auto-release window has passed without a dispute.
     *
     * Must be safe to call twice.
     */
    public function release(SubOrder $subOrder, User|int|null $actor = null): bool;

    /**
     * Whether this driver holds money at all, which is what decides whether a
     * release step is meaningful.
     */
    public function holdsFunds(): bool;
}
