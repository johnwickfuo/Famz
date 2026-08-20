<?php

namespace App\Filament\Mentor\Resources\PayoutAccounts;

use App\Filament\Mentor\Resources\PayoutAccounts\Pages\ListPayoutAccounts;
use App\Filament\Seller\Resources\PayoutAccounts\PayoutAccountResource as SellerPayoutAccountResource;

/**
 * Where a mentor's money goes. The seller resource, unchanged: a bank account
 * belongs to a user, not to a trading identity.
 */
class PayoutAccountResource extends SellerPayoutAccountResource
{
    public static function getPages(): array
    {
        return [
            'index' => ListPayoutAccounts::route('/'),
        ];
    }
}
