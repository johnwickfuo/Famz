<?php

namespace App\Filament\Admin\Resources\SellerProfiles;

use App\Filament\Admin\Resources\SellerProfiles\Pages\EditSellerProfile;
use App\Filament\Admin\Resources\SellerProfiles\Pages\ListSellerProfiles;
use App\Filament\Admin\Resources\SellerProfiles\Schemas\SellerProfileForm;
use App\Filament\Admin\Resources\SellerProfiles\Tables\SellerProfilesTable;
use App\Models\SellerProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Where seller applications are reviewed.
 *
 * There is no "create" page on purpose: a seller account comes from somebody
 * applying, not from an administrator inventing one.
 */
class SellerProfileResource extends Resource
{
    protected static ?string $model = SellerProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'business_name';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Sellers');
    }

    public static function getModelLabel(): string
    {
        return __('seller');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sellers');
    }

    /**
     * The count of applications actually waiting on somebody.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getModel()::query()->awaitingReview()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return SellerProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SellerProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSellerProfiles::route('/'),
            'edit' => EditSellerProfile::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
