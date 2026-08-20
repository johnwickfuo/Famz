<?php

namespace App\Filament\Admin\Resources\Specialisations\Pages;

use App\Filament\Admin\Resources\Specialisations\SpecialisationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSpecialisations extends ListRecords
{
    protected static string $resource = SpecialisationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
