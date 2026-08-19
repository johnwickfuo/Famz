<?php

namespace App\Filament\Admin\Resources\Enrolments\Pages;

use App\Filament\Admin\Resources\Enrolments\EnrolmentResource;
use Filament\Resources\Pages\ListRecords;

class ListEnrolments extends ListRecords
{
    protected static string $resource = EnrolmentResource::class;
}
