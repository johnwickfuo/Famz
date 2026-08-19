<?php

namespace App\Filament\Seller\Resources\PayoutAccounts;

use App\Filament\Seller\Resources\PayoutAccounts\Pages\ListPayoutAccounts;
use App\Filament\Seller\Resources\PayoutAccounts\Tables\PayoutAccountsTable;
use App\Models\PayoutAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Where a seller's money goes.
 *
 * There is no edit page on purpose: an account number cannot be corrected in
 * place, because the name on it was confirmed with the bank for the number as
 * it was. Changing one means adding a new account and checking it again.
 */
class PayoutAccountResource extends Resource
{
    protected static ?string $model = PayoutAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return __('Bank accounts');
    }

    public static function getModelLabel(): string
    {
        return __('bank account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('bank accounts');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedBy(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return PayoutAccountsTable::configure($table);
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
        return ['index' => ListPayoutAccounts::route('/')];
    }
}
