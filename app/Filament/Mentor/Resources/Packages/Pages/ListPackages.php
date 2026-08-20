<?php

namespace App\Filament\Mentor\Resources\Packages\Pages;

use App\Filament\Mentor\Resources\Packages\PackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackages extends ListRecords
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add a package')),
        ];
    }
}
