<?php

namespace App\Filament\Admin\Resources\Disputes;

use App\Filament\Admin\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Admin\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Admin\Resources\Disputes\Tables\DisputesTable;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The arbitration queue.
 *
 * Every open dispute is money frozen — a seller not being paid and a buyer not
 * being refunded — so the badge counts live ones and the default tab is the
 * ones nobody has looked at.
 */
class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('Disputes');
    }

    public static function getModelLabel(): string
    {
        return __('dispute');
    }

    public static function getPluralModelLabel(): string
    {
        return __('disputes');
    }

    public static function getNavigationBadge(): ?string
    {
        $live = Dispute::query()->live()->count();

        return $live > 0 ? (string) $live : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return DisputesTable::configure($table);
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
        return [
            'index' => ListDisputes::route('/'),
            'view' => ViewDispute::route('/{record}'),
        ];
    }
}
