<?php

namespace App\Filament\Admin\Resources\JobListings\Tables;

use App\Enums\JobListingStatus;
use App\Enums\JobType;
use App\Models\JobListing;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JobListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employer')->withCount('applications'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Job'))
                    ->searchable()
                    ->description(fn (JobListing $record): ?string => $record->employer?->business_name)
                    ->wrap(),

                TextColumn::make('job_type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (JobType $state): string => $state->label()),

                TextColumn::make('state')
                    ->label(__('Where'))
                    ->description(fn (JobListing $record): ?string => $record->lga)
                    ->searchable()
                    ->visibleFrom('md'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (JobListingStatus $state): string => $state->label())
                    ->color(fn (JobListingStatus $state): string => $state->filamentColour()),

                TextColumn::make('applications_count')
                    ->label(__('Applied'))
                    ->alignRight()
                    ->visibleFrom('lg'),

                TextColumn::make('pay_min_kobo')
                    ->label(__('Pay'))
                    ->state(fn (JobListing $record): string => $record->payRange() ?? '—')
                    ->visibleFrom('2xl'),

                TextColumn::make('application_deadline')
                    ->label(__('Apply by'))
                    ->date('j M Y')
                    ->placeholder(__('No deadline'))
                    ->sortable()
                    ->visibleFrom('2xl'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(JobListingStatus::options()),
                SelectFilter::make('job_type')->label(__('Type'))->options(JobType::options()),

                Filter::make('on_board')
                    ->label(__('Live on the board'))
                    ->query(fn (Builder $query) => $query->onBoard()),
            ])
            ->recordActions([
                /*
                 * The only action an administrator needs here. Employers run
                 * their own listings; this is for the one asking applicants for
                 * a fee, or reading like something worse.
                 */
                Action::make('takeDown')
                    ->label(__('Take it down'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->button()
                    ->visible(fn (JobListing $record): bool => $record->status->isPublic()
                        && ! $record->status->isFinished())
                    ->modalHeading(__('Take this listing off the board'))
                    ->modalDescription(__('It stops taking applications immediately. Applicants already on it keep their record.'))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Why'))
                            ->required()
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText(__('For the record. You will want it if the employer asks.')),
                    ])
                    ->action(function (JobListing $record): void {
                        $record->forceFill([
                            'status' => JobListingStatus::Closed,
                            'closed_at' => now(),
                        ])->save();

                        Notification::make()->title(__('Taken down'))->success()->send();
                    }),
            ]);
    }
}
