<?php

namespace App\Providers\Filament;

use App\Enums\RoleName;

class MentorPanelProvider extends BasePanelProvider
{
    protected function role(): RoleName
    {
        return RoleName::Mentor;
    }
}
