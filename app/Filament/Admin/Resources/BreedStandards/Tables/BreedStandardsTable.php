<?php

namespace App\Filament\Admin\Resources\BreedStandards\Tables;

use App\Models\BreedStandard;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BreedStandardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Breed, then week. Sorting on breed alone leaves the weeks in
             * whatever order the database felt like, which makes a feeding
             * table almost impossible to check against the printed guide it
             * came from — and checking it against the guide is the entire
             * reason an administrator opens this screen.
             */
            ->defaultSort(fn (Builder $query) => $query->orderBy('breed')->orderBy('week_number'))
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('breed')
                    ->label(__('Breed'))
                    ->description(fn (BreedStandard $record): string => $record->production_type)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('week_number')
                    ->label(__('Week'))
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('avg_feed_g_per_bird_per_day')
                    ->label(__('Feed / bird / day'))
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').' g')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('target_weight_g')
                    ->label(__('Target weight'))
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : number_format((int) $state).' g')
                    ->alignRight()
                    ->visibleFrom('md'),

                TextColumn::make('water_multiplier')
                    ->label(__('Water ×'))
                    ->alignRight()
                    // Last of the columns to earn its width. Held back to the
                    // widest breakpoint so that "In use" and the row actions
                    // stay on screen at 1280, where they were being pushed a
                    // hundred pixels off the right edge of a table that gives
                    // no visual hint it scrolls.
                    ->visibleFrom('2xl'),

                TextColumn::make('source')
                    ->label(__('Source'))
                    /*
                     * Truncated with the full text on hover, rather than
                     * wrapped. A publication name is the same string on all
                     * eight weeks of a breed, and wrapping it turned every row
                     * into a four-line block — twenty-five rows came to four
                     * thousand pixels of a table whose whole purpose is being
                     * scanned against a printed guide.
                     */
                    ->limit(18)
                    ->tooltip(fn (BreedStandard $record): ?string => $record->source)
                    ->visibleFrom('xl'),

                IconColumn::make('is_active')
                    ->label(__('In use'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('breed')
                    ->label(__('Breed'))
                    ->options(fn (): array => BreedStandard::query()
                        ->distinct()
                        ->orderBy('breed')
                        ->pluck('breed', 'breed')
                        ->all()),

                SelectFilter::make('production_type')
                    ->label(__('Production type'))
                    ->options(fn (): array => BreedStandard::query()
                        ->distinct()
                        ->orderBy('production_type')
                        ->pluck('production_type', 'production_type')
                        ->all()),

                TernaryFilter::make('is_active')->label(__('In use')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),

                    /*
                     * Building a table means entering the same breed and source
                     * twenty times with one number different. Replicating a row
                     * and changing the week is the difference between that
                     * being ten minutes of work and an hour of it.
                     */
                    ReplicateAction::make()
                        ->label(__('Duplicate for another week'))
                        ->excludeAttributes(['week_number'])
                        ->form([
                            \Filament\Forms\Components\TextInput::make('week_number')
                                ->label(__('Week'))
                                ->required()
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(120),
                        ])
                        ->beforeReplicaSaved(function (BreedStandard $replica, array $data): void {
                            $replica->week_number = (int) $data['week_number'];
                        }),

                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
