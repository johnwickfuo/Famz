<?php

namespace App\Filament\Admin\Resources\LedgerEntries;

use App\Filament\Admin\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Admin\Resources\LedgerEntries\Tables\LedgerEntriesTable;
use App\Models\WalletTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The whole ledger, searchable.
 *
 * Read-only everywhere, including here. An administrator who could edit an
 * entry could make the books say anything, which would make them worth
 * nothing; corrections are made by writing an Adjustment, never by editing.
 */
class LedgerEntryResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return __('Ledger');
    }

    public static function getModelLabel(): string
    {
        return __('ledger entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ledger');
    }

    public static function table(Table $table): Table
    {
        return LedgerEntriesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListLedgerEntries::route('/')];
    }
}
