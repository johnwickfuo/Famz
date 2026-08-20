<?php

namespace App\Filament\Mentor\Resources\Withdrawals;

use App\Filament\Mentor\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Seller\Resources\Withdrawals\WithdrawalResource as SellerWithdrawalResource;

/**
 * The mentor's payouts.
 *
 * Deliberately the seller resource with a different front door. Payouts,
 * payout accounts and the ledger are all keyed on the USER rather than on a
 * seller profile, so a mentor withdrawing their earnings is the same operation
 * a seller performs — same double-spend guard, same minimum, same two payout
 * modes. Rewriting it here would be a second implementation to keep in step
 * with the first, and money is the last place to want two of anything.
 */
class WithdrawalResource extends SellerWithdrawalResource
{
    public static function getPages(): array
    {
        return [
            'index' => ListWithdrawals::route('/'),
        ];
    }
}
