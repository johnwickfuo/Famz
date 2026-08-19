<?php

namespace App\Filament\Admin\Resources\Withdrawals;

use App\Filament\Admin\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Admin\Resources\Withdrawals\Tables\WithdrawalsTable;
use App\Models\Withdrawal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Everybody's payouts, and the queue of ones waiting to be approved.
 */
class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 20;

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

    /**
     * How many people are waiting on somebody to press approve.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Withdrawal::query()->awaitingApproval()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return WithdrawalsTable::configure($table);
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
        return ['index' => ListWithdrawals::route('/')];
    }
}
