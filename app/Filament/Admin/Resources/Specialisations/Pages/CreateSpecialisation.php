<?php

namespace App\Filament\Admin\Resources\Specialisations\Pages;

use App\Filament\Admin\Resources\Specialisations\SpecialisationResource;
use App\Filament\Admin\Resources\Specialisations\Support\Keywords;
use Filament\Resources\Pages\CreateRecord;

class CreateSpecialisation extends CreateRecord
{
    protected static string $resource = SpecialisationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Keywords::fromForm($data, $this->data['keywords_text'] ?? null);
    }
}
