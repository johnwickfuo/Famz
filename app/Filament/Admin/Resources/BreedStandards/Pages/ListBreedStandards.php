<?php

namespace App\Filament\Admin\Resources\BreedStandards\Pages;

use App\Filament\Admin\Resources\BreedStandards\BreedStandardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBreedStandards extends ListRecords
{
    protected static string $resource = BreedStandardResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
