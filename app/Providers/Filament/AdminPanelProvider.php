<?php

namespace App\Providers\Filament;

use App\Enums\RoleName;
use Filament\Panel;

class AdminPanelProvider extends BasePanelProvider
{
    protected function role(): RoleName
    {
        return RoleName::Admin;
    }

    public function panel(Panel $panel): Panel
    {
        return parent::panel($panel)->default();
    }
}
