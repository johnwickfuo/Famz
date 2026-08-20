<?php

namespace App\Filament\Mentor\Resources\LedgerEntries\Pages;

use App\Filament\Mentor\Resources\LedgerEntries\LedgerEntryResource;
use App\Filament\Seller\Resources\LedgerEntries\Pages\ListLedgerEntries as SellerListLedgerEntries;

class ListLedgerEntries extends SellerListLedgerEntries
{
    protected static string $resource = LedgerEntryResource::class;
}
