<?php

namespace App\Filament\Admin\Resources\Specialisations\Pages;

use App\Filament\Admin\Resources\Specialisations\SpecialisationResource;
use App\Filament\Admin\Resources\Specialisations\Support\Keywords;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSpecialisation extends EditRecord
{
    protected static string $resource = SpecialisationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Keywords::fromForm($data, $this->data['keywords_text'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
