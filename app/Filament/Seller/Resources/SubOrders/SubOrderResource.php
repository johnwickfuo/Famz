<?php

namespace App\Filament\Seller\Resources\SubOrders;

use App\Enums\SubOrderStatus;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Filament\Seller\Resources\SubOrders\Pages\ListSubOrders;
use App\Filament\Seller\Resources\SubOrders\Pages\ViewSubOrder;
use App\Filament\Seller\Resources\SubOrders\Tables\SubOrdersTable;
use App\Models\SubOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orders waiting on this seller.
 *
 * Scoped exactly as their listings are: the base query never contains another
 * seller's rows, and the policy is consulted as well.
 */
class SubOrderResource extends Resource
{
    protected static ?string $model = SubOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = -10;

    public static function getNavigationLabel(): string
    {
        return __('Orders');
    }

    public static function getModelLabel(): string
    {
        return __('order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('orders');
    }

    /**
     * How many are waiting on the seller to do something.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getEloquentQuery()
            ->where('status', SubOrderStatus::Pending)
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->forSeller(ProductResource::currentSeller())
            // Nothing unpaid is any of the seller's business: an order that was
            // never paid for is not an order.
            ->whereHas('order', fn (Builder $order) => $order->paid());
    }

    public static function table(Table $table): Table
    {
        return SubOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubOrders::route('/'),
            'view' => ViewSubOrder::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
