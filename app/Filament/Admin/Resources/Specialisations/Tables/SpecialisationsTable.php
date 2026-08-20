<?php

namespace App\Filament\Admin\Resources\Specialisations\Tables;

use App\Models\Specialisation;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SpecialisationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('mentors'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Specialisation'))
                    ->description(fn (Specialisation $record): ?string => $record->description)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('sector')
                    ->label(__('Sector'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('mentors_count')
                    ->label(__('Mentors'))
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('keyword_count')
                    ->label(__('Keywords'))
                    ->state(fn (Specialisation $record): int => count($record->keywords ?? []))
                    ->alignRight()
                    ->visibleFrom('lg'),

                IconColumn::make('is_active')
                    ->label(__('In use'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('sector')
                    ->label(__('Sector'))
                    ->options(fn (): array => Specialisation::query()
                        ->distinct()
                        ->orderBy('sector')
                        ->pluck('sector', 'sector')
                        ->all()),

                TernaryFilter::make('is_active')->label(__('In use')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        // Deleting one silently unfiles every mentor who chose
                        // it, and they would have no idea why the work stopped.
                        ->before(function (Specialisation $record, DeleteAction $action): void {
                            if ($record->mentors()->count() > 0) {
                                Notification::make()
                                    ->title(__('Mentors are filed under this'))
                                    ->body(__('Turn it off instead — deleting it would take it off their profiles without telling them.'))
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }
}
