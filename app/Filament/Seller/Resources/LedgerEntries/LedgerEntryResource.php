<?php

namespace App\Filament\Seller\Resources\LedgerEntries;

use App\Filament\Seller\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Seller\Resources\LedgerEntries\Tables\LedgerEntriesTable;
use App\Models\WalletTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The seller's own statement.
 *
 * Read-only by construction: there is no create, edit or delete page and the
 * policy refuses all three. A ledger somebody can edit is not a ledger.
 */
class LedgerEntryResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('Statement');
    }

    public static function getModelLabel(): string
    {
        return __('entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('statement');
    }

    public static function getEloquentQuery(): Builder
    {
        // The seller's own account and nothing else. The platform's entries
        // live under a null user_id and are none of their business.
        return parent::getEloquentQuery()->where('user_id', auth()->id());
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
