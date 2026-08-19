<?php

namespace App\Filament\Admin\Resources\Enrolments\Tables;

use App\Models\Course;
use App\Models\Enrolment;
use App\Support\Money;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnrolmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('enrolled_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'course', 'certificate']))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('Student'))
                    ->description(fn (Enrolment $record): ?string => $record->user?->email)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('course.title')
                    ->label(__('Course'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('enrolled_at')
                    ->label(__('Enrolled'))
                    ->dateTime('j M Y')
                    ->sortable()
                    ->placeholder(__('Not paid for')),

                TextColumn::make('price_paid_kobo')
                    ->label(__('Paid'))
                    ->formatStateUsing(fn (int $state): string => Money::fromKobo($state))
                    ->alignRight()
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label(__('Total'))
                            ->formatStateUsing(fn (?int $state): string => Money::fromKobo((int) $state)),
                    )
                    ->visibleFrom('md'),

                TextColumn::make('progress')
                    ->label(__('Progress'))
                    ->state(fn (Enrolment $record): string => $record->progressPercent().'%')
                    ->alignRight()
                    ->visibleFrom('lg'),

                TextColumn::make('completed_at')
                    ->label(__('Finished'))
                    ->dateTime('j M Y')
                    ->placeholder(__('Not yet'))
                    ->visibleFrom('lg'),

                TextColumn::make('certificate.verification_code')
                    ->label(__('Certificate'))
                    ->placeholder(__('None'))
                    ->copyable()
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label(__('Course'))
                    ->options(fn (): array => Course::query()
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->searchable(),

                Filter::make('active')
                    ->label(__('Paid for'))
                    ->query(fn (Builder $query) => $query->whereNotNull('enrolled_at')),

                Filter::make('finished')
                    ->label(__('Finished'))
                    ->query(fn (Builder $query) => $query->whereNotNull('completed_at')),
            ]);
    }
}
