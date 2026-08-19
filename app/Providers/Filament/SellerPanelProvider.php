<?php

namespace App\Providers\Filament;

use App\Enums\RoleName;
use App\Filament\Seller\Pages\Earnings;

class SellerPanelProvider extends BasePanelProvider
{
    protected function role(): RoleName
    {
        return RoleName::Seller;
    }

    /**
     * A seller opens their panel on the money. It is what they came for.
     */
    protected function dashboardPage(): string
    {
        return Earnings::class;
    }
}
