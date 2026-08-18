<?php

namespace App\Providers\Filament;

use App\Enums\RoleName;

class SellerPanelProvider extends BasePanelProvider
{
    protected function role(): RoleName
    {
        return RoleName::Seller;
    }
}
