<?php

namespace App\Filament\Seller\Resources\Products;

use App\Filament\Seller\Resources\Products\Pages\CreateProduct;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Filament\Seller\Resources\Products\Schemas\ProductForm;
use App\Filament\Seller\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Models\SellerProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * A seller's own listings, and only their own.
 *
 * Ownership is enforced twice on purpose. The base query is scoped to the
 * signed-in seller, so a listing belonging to somebody else is not in the set
 * to begin with; and ProductPolicy is consulted for every read and write, so a
 * scope that is ever forgotten or bypassed still cannot expose another
 * seller's record. Either mechanism alone would be enough on a good day. Both
 * together are what makes this safe on a bad one.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('My listings');
    }

    public static function getModelLabel(): string
    {
        return __('listing');
    }

    public static function getPluralModelLabel(): string
    {
        return __('listings');
    }

    /**
     * The seller this panel is acting as, or null if the signed-in user is not
     * an approved seller.
     *
     * Read straight from the database rather than through the relation: a
     * relation loaded earlier in the request stays cached, and a stale null
     * here would scope the whole panel to nothing.
     */
    public static function currentSeller(): ?SellerProfile
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        $seller = SellerProfile::query()->where('user_id', $userId)->first();

        return $seller?->canSell() ? $seller : null;
    }

    /**
     * The first line of defence: a query that never contains another seller's
     * rows. scopeOwnedBy() fails closed on a null seller — it matches nothing
     * rather than everything.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ownedBy(static::currentSeller());
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
