<?php

namespace App\Filament\Mentor\Resources\Packages\Pages;

use App\Filament\Mentor\Resources\Packages\PackageResource;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    protected static string $resource = PackageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['price_kobo'] = Money::toKobo($data['price_naira'] ?? 0);

        unset($data['price_naira'], $data['mentor_profile_id']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
