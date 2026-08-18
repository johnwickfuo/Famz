<?php

namespace App\Filament\Admin\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Filament\Admin\Actions\ProductReviewActions;
use App\Models\Product;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['seller', 'category', 'images']))
            ->columns([
                ImageColumn::make('images.0.path')
                    ->label(__('Photo'))
                    ->disk(config('filesystems.default'))
                    ->square(),

                TextColumn::make('name')
                    ->label(__('Listing'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Product $record): string => $record->category?->pathName() ?? '')
                    ->wrap(),

                TextColumn::make('seller.business_name')
                    ->label(__('Seller'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Product $record): string => $record->seller?->location() ?? ''),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->color(fn (ProductStatus $state): string => match ($state) {
                        ProductStatus::Active => 'success',
                        ProductStatus::PendingReview => 'warning',
                        ProductStatus::Rejected => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('price_kobo')
                    ->label(__('Price'))
                    ->formatStateUsing(fn (int $state): string => Money::fromKobo($state))
                    ->alignRight()
                    ->sortable(),

                // The two flags a reviewer most needs to see at a glance: they
                // are the ones with a handling obligation attached.
                IconColumn::make('is_live_animal')->label(__('Live'))->boolean(),
                IconColumn::make('is_perishable')->label(__('Perishable'))->boolean(),

                TextColumn::make('created_at')
                    ->label(__('Listed'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ProductStatus::options())
                    ->default(ProductStatus::PendingReview->value),

                SelectFilter::make('seller')
                    ->label(__('Seller'))
                    ->relationship('seller', 'business_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_live_animal')->label(__('Live animals')),
                TernaryFilter::make('is_perishable')->label(__('Perishable')),
            ])
            ->recordActions([
                ...ProductReviewActions::all(),
                ActionGroup::make([EditAction::make()]),
            ]);
    }
}
