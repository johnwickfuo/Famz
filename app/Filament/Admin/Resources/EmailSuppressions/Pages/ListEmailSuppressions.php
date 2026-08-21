<?php

namespace App\Filament\Admin\Resources\EmailSuppressions\Pages;

use App\Filament\Admin\Resources\EmailSuppressions\EmailSuppressionResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailSuppressions extends ListRecords
{
    protected static string $resource = EmailSuppressionResource::class;
}
