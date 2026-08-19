<?php

namespace App\Filament\Seller\Resources\Withdrawals;

use App\Enums\PayoutMode;
use App\Filament\Seller\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Seller\Resources\Withdrawals\Tables\WithdrawalsTable;
use App\Models\Withdrawal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The seller's payouts.
 *
 * Under automatic payouts the seller cannot ask for one — the schedule does it
 * — so the request button disappears rather than failing when pressed.
 */
class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationLabel(): string
    {
        return __('Payouts');
    }

    public static function getModelLabel(): string
    {
        return __('payout');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payouts');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function table(Table $table): Table
    {
        return WithdrawalsTable::configure($table);
    }

    /**
     * Requests are made through the header action, which knows the payout
     * mode; a bare create form would let somebody ask on a platform that pays
     * automatically.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function sellersMayRequest(): bool
    {
        return PayoutMode::current() === PayoutMode::ManualRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return ['index' => ListWithdrawals::route('/')];
    }
}
