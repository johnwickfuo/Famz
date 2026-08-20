<?php

namespace App\Filament\Mentor\Resources\Packages\Pages;

use App\Filament\Mentor\Resources\Packages\PackageResource;
use App\Models\MentorshipPackage;
use App\Support\Money;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePackage extends CreateRecord
{
    protected static string $resource = PackageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['price_kobo'] = Money::toKobo($data['price_naira'] ?? 0);

        unset($data['price_naira'], $data['mentor_profile_id']);

        return $data;
    }

    /**
     * The owner comes from the session, never from the form: a mentor must not
     * be able to file a package under somebody else.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $mentor = PackageResource::currentMentor();

        abort_if($mentor === null, 403, __('Only a mentor can add a package.'));

        $package = new MentorshipPackage($data);
        $package->mentor_profile_id = $mentor->getKey();
        $package->save();

        return $package;
    }
}
