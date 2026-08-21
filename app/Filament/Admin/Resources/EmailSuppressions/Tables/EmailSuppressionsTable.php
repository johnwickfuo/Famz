<?php

namespace App\Filament\Admin\Resources\EmailSuppressions\Tables;

use App\Models\EmailSuppression;
use App\Services\Mail\SuppressionList;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmailSuppressionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('suppressed_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'releasedBy']))
            ->emptyStateHeading(__('Nothing is undeliverable'))
            ->emptyStateDescription(__('Every address the platform has written to has accepted mail.'))
            ->columns([
                TextColumn::make('email')
                    ->label(__('Address'))
                    // The linked account, when there is one. Most suppressed
                    // addresses belong to nobody: a consultation booked by
                    // somebody who never registered, a quotation client.
                    ->description(fn (EmailSuppression $record): string => $record->user?->name
                        ?? __('not an account'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('type')
                    ->label(__('Why'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        EmailSuppression::TYPE_BOUNCE => __('Address does not work'),
                        EmailSuppression::TYPE_COMPLAINT => __('Marked us as spam'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => $state === EmailSuppression::TYPE_COMPLAINT
                        ? 'danger'
                        : 'warning'),

                TextColumn::make('reason')
                    ->label(__('What the provider said'))
                    ->limit(60)
                    ->tooltip(fn (EmailSuppression $record): ?string => $record->reason)
                    ->visibleFrom('lg'),

                TextColumn::make('provider')
                    ->label(__('Reported by'))
                    ->visibleFrom('xl'),

                TextColumn::make('suppressed_at')
                    ->label(__('Since'))
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('released_at')
                    ->label(__('Cleared'))
                    ->dateTime('j M Y')
                    ->placeholder(__('Still blocked'))
                    ->description(fn (EmailSuppression $record): ?string => $record->releasedBy?->name)
                    ->visibleFrom('md'),
            ])
            ->filters([
                Filter::make('active')
                    ->label(__('Still blocked'))
                    ->default()
                    ->query(fn (Builder $query) => $query->active()),

                SelectFilter::make('type')
                    ->label(__('Why'))
                    ->options([
                        EmailSuppression::TYPE_BOUNCE => __('Address does not work'),
                        EmailSuppression::TYPE_COMPLAINT => __('Marked us as spam'),
                    ]),
            ])
            ->recordActions([
                Action::make('release')
                    ->label(__('Send to them again'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->visible(fn (EmailSuppression $record): bool => $record->isActive())
                    ->modalHeading(__('Start sending to this address again'))
                    ->modalDescription(fn (EmailSuppression $record): string => match ($record->type) {
                        EmailSuppression::TYPE_COMPLAINT => __('This person marked our mail as spam. Only clear this if they have asked you to — sending to somebody who complained is what gets a sending domain blocked.'),
                        default => __('Only clear this if the address has been corrected or the mailbox fixed. If it is still dead, the next bounce will block it again and the provider will think less of us for trying.'),
                    })
                    ->action(function (EmailSuppression $record): void {
                        app(SuppressionList::class)->release($record->email, auth()->user());

                        Notification::make()
                            ->title(__('The platform will write to that address again'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
