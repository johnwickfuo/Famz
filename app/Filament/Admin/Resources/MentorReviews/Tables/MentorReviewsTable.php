<?php

namespace App\Filament\Admin\Resources\MentorReviews\Tables;

use App\Enums\ReviewStatus;
use App\Models\MentorReview;
use App\Services\Mentorship\ReviewService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class MentorReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest waiting first: a queue, not a feed.
            ->defaultSort('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['mentor.user', 'client', 'engagement']))
            ->columns([
                TextColumn::make('rating')
                    ->label(__('Rating'))
                    ->formatStateUsing(fn (int $state): string => $state.'/5')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('mentor.user.name')
                    ->label(__('About'))
                    ->searchable()
                    ->wrap(),

                TextColumn::make('client.name')
                    ->label(__('From'))
                    ->searchable()
                    ->visibleFrom('lg'),

                TextColumn::make('comment')
                    ->label(__('What they wrote'))
                    ->placeholder(__('No comment'))
                    ->wrap()
                    ->limit(300),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (ReviewStatus $state): string => $state->label())
                    ->color(fn (ReviewStatus $state): string => match ($state) {
                        ReviewStatus::Approved => 'success',
                        ReviewStatus::Pending => 'warning',
                        ReviewStatus::Rejected => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label(__('Left'))
                    ->dateTime('j M Y')
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ReviewStatus::options())
                    ->default(ReviewStatus::Pending->value),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label(__('Publish'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription(__('It appears on the mentor\'s profile and counts toward their rating.'))
                        ->visible(fn (MentorReview $record): bool => $record->status !== ReviewStatus::Approved)
                        ->action(function (MentorReview $record): void {
                            app(ReviewService::class)->approve($record, auth()->user());

                            Notification::make()->title(__('Published'))->success()->send();
                        }),

                    Action::make('reject')
                        ->label(__('Do not publish'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (MentorReview $record): bool => $record->status !== ReviewStatus::Rejected)
                        ->schema([
                            Textarea::make('note')
                                ->label(__('Why'))
                                ->required()
                                ->rows(3)
                                ->maxLength(500)
                                // Both sides read it. A rejection nobody
                                // explains is indistinguishable from censorship.
                                ->helperText(__('The mentor sees this. Say what was wrong with it.')),
                        ])
                        ->action(function (MentorReview $record, array $data): void {
                            try {
                                app(ReviewService::class)->reject($record, auth()->user(), $data['note']);
                            } catch (RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()->title(__('Not published'))->success()->send();
                        }),
                ]),
            ]);
    }
}
