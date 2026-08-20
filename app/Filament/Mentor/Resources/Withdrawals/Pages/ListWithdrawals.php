<?php

namespace App\Filament\Mentor\Resources\Withdrawals\Pages;

use App\Filament\Mentor\Resources\Withdrawals\WithdrawalResource;
use App\Filament\Seller\Resources\Withdrawals\Pages\ListWithdrawals as SellerListWithdrawals;

class ListWithdrawals extends SellerListWithdrawals
{
    protected static string $resource = WithdrawalResource::class;
}
