<?php

namespace App\Filament\Admin\Resources\WorkerProfiles\Tables;

use App\Enums\WorkerAvailability;
use App\Enums\WorkTypeWanted;
use App\Models\WorkerProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkerProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('skills')->withCount('applications'))
            ->columns([
                /*
                 * No phone column, and no way to add one by widening this list.
                 * An administrator with a reason to ring somebody can open the
                 * public profile like anybody else, where the disclosure gets
                 * recorded.
                 */
                TextColumn::make('full_name')
                    ->label(__('Worker'))
                    ->searchable()
                    ->description(fn (WorkerProfile $record): ?string => $record->place())
                    ->wrap(),

                TextColumn::make('years_experience')
                    ->label(__('Experience'))
                    ->state(fn (WorkerProfile $record): string => $record->experienceLabel())
                    ->visibleFrom('md'),

                TextColumn::make('work_type_wanted')
                    ->label(__('Wants'))
                    ->badge()
                    ->formatStateUsing(fn (WorkTypeWanted $state): string => $state->label())
                    ->visibleFrom('lg'),

                TextColumn::make('availability')
                    ->label(__('Can start'))
                    ->badge()
                    ->formatStateUsing(fn (WorkerAvailability $state): string => $state->label())
                    ->color(fn (WorkerAvailability $state): string => match ($state->badgeTone()) {
                        'success' => 'success',
                        'info' => 'info',
                        default => 'gray',
                    })
                    ->visibleFrom('xl'),

                IconColumn::make('is_open_to_work')
                    ->label(__('Looking'))
                    ->boolean(),

                TextColumn::make('applications_count')
                    ->label(__('Applied'))
                    ->alignRight()
                    ->visibleFrom('lg'),

                TextColumn::make('rating_average')
                    ->label(__('Rating'))
                    ->formatStateUsing(fn (?string $state, WorkerProfile $record): string => $state === null
                        ? '—'
                        : $state.' ('.$record->rating_count.')')
                    ->visibleFrom('2xl'),
            ])
            ->filters([
                SelectFilter::make('work_type_wanted')->label(__('Wants'))->options(WorkTypeWanted::options()),
                SelectFilter::make('availability')->label(__('Can start'))->options(WorkerAvailability::options()),

                Filter::make('open')
                    ->label(__('Looking for work'))
                    ->query(fn (Builder $query) => $query->openToWork()),

                Filter::make('relocating')
                    ->label(__('Will relocate'))
                    ->query(fn (Builder $query) => $query->where('willing_to_relocate', true)),
            ])
            ->recordActions([
                Action::make('suspend')
                    ->label(fn (WorkerProfile $record): string => $record->is_active
                        ? __('Take down')
                        : __('Put back'))
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn (WorkerProfile $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (WorkerProfile $record): string => $record->is_active
                        ? __('The profile stops appearing to employers. Their applications and ratings stay.')
                        : __('The profile becomes visible to employers again.'))
                    ->schema(fn (WorkerProfile $record): array => $record->is_active ? [
                        Textarea::make('reason')
                            ->label(__('Why'))
                            ->required()
                            ->rows(3)
                            ->maxLength(500),
                    ] : [])
                    ->action(function (WorkerProfile $record): void {
                        $record->forceFill(['is_active' => ! $record->is_active])->save();

                        Notification::make()
                            ->title($record->is_active ? __('Back up') : __('Taken down'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
