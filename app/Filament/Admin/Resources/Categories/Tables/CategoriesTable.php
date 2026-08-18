<?php

namespace App\Filament\Admin\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent')->withCount('products'))
            ->columns([
                // The full path rather than the bare name: "Feeders" alone is
                // ambiguous once the tree has eighty nodes in it.
                TextColumn::make('name')
                    ->label(__('Category'))
                    ->formatStateUsing(fn (Category $record): string => $record->pathName())
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('slug')
                    ->label(__('Slug'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),

                TextColumn::make('products_count')
                    ->label(__('Listings'))
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label(__('Order'))
                    ->alignRight()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                Filter::make('roots')
                    ->label(__('Top-level only'))
                    ->query(fn (Builder $query) => $query->whereNull('parent_id')),

                SelectFilter::make('parent_id')
                    ->label(__('Inside branch'))
                    ->options(fn (): array => Category::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Category $category): array => [$category->id => $category->pathName()])
                        ->all())
                    ->searchable(),

                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        // Deleting a branch takes its children with it, and a
                        // category with listings on it would orphan them.
                        ->before(function (Category $record, DeleteAction $action): void {
                            $withListings = Category::query()
                                ->whereIn('id', $record->descendantIds())
                                ->withCount('products')
                                ->get()
                                ->firstWhere('products_count', '>', 0);

                            if ($withListings !== null) {
                                Notification::make()
                                    ->title(__('This branch still has listings'))
                                    ->body(__(':category has :count listing(s). Move or remove them first.', [
                                        'category' => $withListings->pathName(),
                                        'count' => $withListings->products_count,
                                    ]))
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
