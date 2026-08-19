<?php

namespace App\Filament\Seller\Resources\LedgerEntries\Pages;

use App\Filament\Seller\Resources\LedgerEntries\LedgerEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
