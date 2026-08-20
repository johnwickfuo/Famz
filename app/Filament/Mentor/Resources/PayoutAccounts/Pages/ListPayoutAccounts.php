<?php

namespace App\Filament\Mentor\Resources\PayoutAccounts\Pages;

use App\Filament\Mentor\Resources\PayoutAccounts\PayoutAccountResource;
use App\Filament\Seller\Resources\PayoutAccounts\Pages\ListPayoutAccounts as SellerListPayoutAccounts;

class ListPayoutAccounts extends SellerListPayoutAccounts
{
    protected static string $resource = PayoutAccountResource::class;
}
