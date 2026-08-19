<?php

namespace App\Filament\Admin\Resources\Courses\RelationManagers;

use App\Models\Enrolment;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is on this course, and how far they got.
 *
 * Read-only on purpose. Enrolment follows payment, and an administrator who
 * needs to grant or withdraw access should be doing it somewhere that records
 * why — not by editing a row in a list.
 */
class EnrolmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrolments';

    protected static ?string $title = 'Students';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('enrolled_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'certificate']))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('Student'))
                    ->description(fn (Enrolment $record): ?string => $record->user?->email)
                    ->searchable()
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
                    ->visibleFrom('md'),

                TextColumn::make('progress')
                    ->label(__('Progress'))
                    ->state(fn (Enrolment $record): string => $record->progressPercent().'%')
                    ->alignRight(),

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
                Filter::make('finished')
                    ->label(__('Finished only'))
                    ->query(fn (Builder $query) => $query->whereNotNull('completed_at')),

                Filter::make('certificated')
                    ->label(__('Has a certificate'))
                    ->query(fn (Builder $query) => $query->whereHas('certificate')),
            ]);
    }
}
