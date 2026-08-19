<?php

namespace App\Filament\Admin\Resources\Certificates\Tables;

use App\Models\Certificate;
use App\Models\Course;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['holder', 'course']))
            ->columns([
                TextColumn::make('verification_code')
                    ->label(__('Code'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('holder_name')
                    ->label(__('Awarded to'))
                    ->description(fn (Certificate $record): ?string => $record->holder?->email)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('course_title')
                    ->label(__('Course'))
                    ->searchable()
                    ->wrap()
                    ->visibleFrom('md'),

                TextColumn::make('issued_at')
                    ->label(__('Issued'))
                    ->dateTime('j M Y')
                    ->sortable(),

                // The name as it stood that day, which is the whole point of
                // storing it: it will not match the company's current name for
                // ever, and it must not be quietly corrected.
                TextColumn::make('issuer_name')
                    ->label(__('Issued as'))
                    ->visibleFrom('xl'),

                TextColumn::make('quiz_score_percent')
                    ->label(__('Score'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : $state.'%')
                    ->alignRight()
                    ->visibleFrom('lg'),

                IconColumn::make('revoked_at')
                    ->label(__('Valid'))
                    ->boolean()
                    ->getStateUsing(fn (Certificate $record): bool => $record->isValid()),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label(__('Course'))
                    ->options(fn (): array => Course::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable(),

                Filter::make('revoked')
                    ->label(__('Withdrawn only'))
                    ->query(fn (Builder $query) => $query->whereNotNull('revoked_at')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('check')
                        ->label(__('Open the check page'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (Certificate $record): string => route('certificates.verify', $record->verification_code))
                        ->openUrlInNewTab(),

                    Action::make('revoke')
                        ->label(__('Withdraw'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Certificate $record): bool => $record->isValid())
                        ->schema([
                            Textarea::make('reason')
                                ->label(__('Why'))
                                ->required()
                                ->rows(3)
                                ->maxLength(500)
                                // Shown publicly on the check page, so whoever
                                // is holding the printed copy reads exactly
                                // this sentence.
                                ->helperText(__('This appears on the public check page.')),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription(__('The check page will say this certificate has been withdrawn.'))
                        ->action(function (Certificate $record, array $data): void {
                            $record->forceFill([
                                'revoked_at' => now(),
                                'revocation_reason' => $data['reason'],
                            ])->save();

                            Notification::make()->title(__('Withdrawn'))->success()->send();
                        }),

                    Action::make('restore')
                        ->label(__('Reinstate'))
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (Certificate $record): bool => ! $record->isValid())
                        ->requiresConfirmation()
                        ->action(function (Certificate $record): void {
                            $record->forceFill(['revoked_at' => null, 'revocation_reason' => null])->save();

                            Notification::make()->title(__('Reinstated'))->success()->send();
                        }),
                ]),
            ]);
    }
}
