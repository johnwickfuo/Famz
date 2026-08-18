<?php

namespace App\Filament\Seller\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'images']))
            ->columns([
                ImageColumn::make('images.0.path')
                    ->label(__('Photo'))
                    ->disk(config('filesystems.default'))
                    ->square()
                    ->defaultImageUrl(null),

                TextColumn::make('name')
                    ->label(__('Listing'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Product $record): string => $record->category?->pathName() ?? '')
                    ->wrap(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->color(fn (ProductStatus $state): string => match ($state) {
                        ProductStatus::Active => 'success',
                        ProductStatus::PendingReview => 'warning',
                        ProductStatus::Rejected => 'danger',
                        ProductStatus::OutOfStock => 'gray',
                        ProductStatus::Draft => 'gray',
                    })
                    ->description(fn (Product $record): ?string => $record->status === ProductStatus::Rejected
                        ? $record->review_notes
                        : null)
                    ->sortable(),

                TextColumn::make('price_kobo')
                    ->label(__('Price'))
                    ->formatStateUsing(fn (int $state, Product $record): string => Money::fromKobo($state).' '.$record->unit_of_measure->label())
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('stock_quantity')
                    ->label(__('Stock'))
                    ->alignRight()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'gray' : 'danger'),

                IconColumn::make('is_live_animal')
                    ->label(__('Live'))
                    ->boolean()
                    ->toggleable(),

                IconColumn::make('is_perishable')
                    ->label(__('Perishable'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('views_count')
                    ->label(__('Views'))
                    ->alignRight()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ProductStatus::options()),

                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_live_animal')->label(__('Live animals')),
                TernaryFilter::make('is_perishable')->label(__('Perishable')),
            ])
            ->recordActions([
                // Restocking is the thing a seller does most often, so it is
                // one click from the list rather than buried in the form.
                Action::make('updateStock')
                    ->label(__('Stock'))
                    ->icon('heroicon-o-archive-box')
                    ->modalHeading(fn (Product $record): string => __('Stock for :name', ['name' => $record->name]))
                    ->modalSubmitActionLabel(__('Update stock'))
                    ->authorize(fn (Product $record): bool => auth()->user()->can('update', $record))
                    ->fillForm(fn (Product $record): array => ['stock_quantity' => $record->stock_quantity])
                    ->schema([
                        TextInput::make('stock_quantity')
                            ->label(__('How many do you have?'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText(__('Zero shows the listing as out of stock rather than removing it.')),
                    ])
                    ->action(function (Product $record, array $data): void {
                        // The model puts a live listing out of stock at zero and
                        // brings it back when stock returns.
                        $record->update(['stock_quantity' => (int) $data['stock_quantity']]);

                        Notification::make()
                            ->title(__('Stock updated'))
                            ->body($record->fresh()->status->label())
                            ->success()
                            ->send();
                    }),

                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No listings yet'))
            ->emptyStateDescription(__('Add your first product and send it for review.'));
    }
}
