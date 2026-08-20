<?php

namespace App\Filament\Admin\Resources\JobRatings\Tables;

use App\Enums\RatingParty;
use App\Enums\RatingStatus;
use App\Models\JobRating;
use App\Services\Jobs\RatingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class JobRatingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first: a rating waiting three days is the one somebody is
            // wondering about.
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'application.worker',
                'application.listing.employer',
                'author',
            ]))
            ->recordClasses(fn (JobRating $record): ?string => $record->status === RatingStatus::Pending
                ? 'bg-warning-50 dark:bg-warning-950/30'
                : null)
            ->columns([
                TextColumn::make('rating')
                    ->label(__('Score'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state.'/5')
                    ->color(fn (int $state): string => match (true) {
                        $state >= 4 => 'success',
                        $state === 3 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('rated_by')
                    ->label(__('Written by'))
                    ->formatStateUsing(fn (RatingParty $state): string => $state->label())
                    ->description(fn (JobRating $record): ?string => $record->rated_by === RatingParty::Employer
                        ? $record->application?->listing?->employer?->business_name
                        : $record->application?->worker?->full_name),

                TextColumn::make('subject')
                    ->label(__('About'))
                    ->state(fn (JobRating $record): string => $record->rated_by === RatingParty::Employer
                        ? ($record->application?->worker?->full_name ?? '—')
                        : ($record->application?->listing?->employer?->business_name ?? '—')),

                TextColumn::make('comment')
                    ->label(__('What they said'))
                    ->placeholder(__('Score only'))
                    ->limit(70)
                    ->tooltip(fn (JobRating $record): ?string => $record->comment)
                    ->visibleFrom('lg'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (RatingStatus $state): string => $state->label())
                    ->color(fn (RatingStatus $state): string => $state->filamentColour()),

                TextColumn::make('created_at')
                    ->label(__('Written'))
                    ->since()
                    ->sortable()
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(RatingStatus::options()),
                SelectFilter::make('rated_by')->label(__('Side'))->options(RatingParty::options()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Publish it'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->button()
                    ->visible(fn (JobRating $record): bool => $record->status !== RatingStatus::Approved)
                    ->requiresConfirmation()
                    ->modalDescription(__('It appears on their profile and counts toward their average.'))
                    ->action(fn (JobRating $record) => self::moderate($record, RatingStatus::Approved, null)),

                Action::make('reject')
                    ->label(__('Do not publish'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (JobRating $record): bool => $record->status !== RatingStatus::Rejected)
                    ->schema([
                        Textarea::make('note')
                            ->label(__('Why'))
                            ->required()
                            ->rows(3)
                            ->maxLength(500)
                            // Kept because "you took my review down" is a thing
                            // somebody will say, and a reason is the answer.
                            ->helperText(__('Kept on the record. The author is not shown it, but you will want it if they ask.')),
                    ])
                    ->action(fn (JobRating $record, array $data) => self::moderate(
                        $record,
                        RatingStatus::Rejected,
                        $data['note'] ?? null,
                    )),
            ]);
    }

    private static function moderate(JobRating $rating, RatingStatus $status, ?string $note): void
    {
        try {
            app(RatingService::class)->moderate($rating, $status, auth()->user(), $note);
        } catch (RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($status === RatingStatus::Approved ? __('Published') : __('Not published'))
            ->success()
            ->send();
    }
}
