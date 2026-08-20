<?php

namespace App\Filament\Admin\Resources\ActivityLog\Pages;

use App\Filament\Admin\Resources\ActivityLog\ActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    /**
     * No header actions. Nothing here is created by hand.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
