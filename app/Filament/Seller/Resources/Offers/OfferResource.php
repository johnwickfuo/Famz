<?php

namespace App\Filament\Seller\Resources\Offers;

use App\Filament\Seller\Resources\Offers\Pages\ListOffers;
use App\Filament\Seller\Resources\Offers\Tables\OffersTable;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Offer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Offers waiting on this seller.
 *
 * Scoped the way everything in this panel is: by the seller profile, twice
 * over — the query below and OfferPolicy. An offer carries what somebody is
 * prepared to pay, which is the last thing that should leak to a competitor.
 */
class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = -5;

    public static function getNavigationLabel(): string
    {
        return __('Offers');
    }

    public static function getModelLabel(): string
    {
        return __('offer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('offers');
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getEloquentQuery()
            ->where('responder_id', auth()->id())
            ->open()
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forSeller(ProductResource::currentSeller());
    }

    public static function table(Table $table): Table
    {
        return OffersTable::configure($table);
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
        return ['index' => ListOffers::route('/')];
    }
}
