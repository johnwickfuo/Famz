<?php

namespace App\Filament\Mentor\Resources\Reviews\Tables;

use App\Enums\ReviewStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'engagement']))
            ->columns([
                TextColumn::make('rating')
                    ->label(__('Rating'))
                    ->formatStateUsing(fn (int $state): string => $state.'/5')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('comment')
                    ->label(__('What they said'))
                    ->placeholder(__('No comment'))
                    ->wrap()
                    ->limit(200),

                TextColumn::make('engagement.package_title')
                    ->label(__('For'))
                    ->visibleFrom('lg'),

                TextColumn::make('status')
                    ->label(__('Published?'))
                    ->badge()
                    ->formatStateUsing(fn (ReviewStatus $state): string => $state->label())
                    ->color(fn (ReviewStatus $state): string => match ($state) {
                        ReviewStatus::Approved => 'success',
                        ReviewStatus::Pending => 'warning',
                        ReviewStatus::Rejected => 'gray',
                    }),

                // The moderator's reason, shown to the mentor. A rejection
                // nobody explains looks like censorship even when it was not.
                TextColumn::make('moderation_note')
                    ->label(__('Note from us'))
                    ->placeholder('—')
                    ->wrap()
                    ->visibleFrom('xl'),

                TextColumn::make('created_at')
                    ->label(__('Left'))
                    ->dateTime('j M Y')
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ReviewStatus::options()),
            ])
            ->emptyStateHeading(__('No reviews yet'))
            ->emptyStateDescription(__('A client can leave one once an engagement is finished.'));
    }
}
