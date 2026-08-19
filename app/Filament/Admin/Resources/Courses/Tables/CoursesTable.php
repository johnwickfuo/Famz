<?php

namespace App\Filament\Admin\Resources\Courses\Tables;

use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every course, with the three numbers that decide whether it was worth
 * writing: how many bought it, what it earned, and how many finished it.
 */
class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('category')
                ->withCount([
                    'enrolments as students_count' => fn (Builder $q) => $q->whereNotNull('enrolled_at'),
                    'enrolments as finished_count' => fn (Builder $q) => $q->whereNotNull('completed_at'),
                    'certificates as certificates_count',
                ])
                ->withSum([
                    'enrolments as revenue_kobo' => fn (Builder $q) => $q->whereNotNull('enrolled_at'),
                ], 'price_paid_kobo'))
            ->columns([
                ImageColumn::make('cover_image')
                    ->label(__('Cover'))
                    ->disk('public')
                    ->visibleFrom('lg'),

                TextColumn::make('title')
                    ->label(__('Course'))
                    ->description(fn (Course $record): ?string => $record->category?->pathName())
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (CourseStatus $state): string => $state->label())
                    ->color(fn (CourseStatus $state): string => match ($state) {
                        CourseStatus::Published => 'success',
                        CourseStatus::Draft => 'warning',
                        CourseStatus::Archived => 'gray',
                    }),

                TextColumn::make('price_kobo')
                    ->label(__('Price'))
                    ->formatStateUsing(fn (Course $record): string => $record->price())
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('students_count')
                    ->label(__('Students'))
                    ->alignRight()
                    ->sortable(),

                // The point of the whole academy, in one column.
                TextColumn::make('revenue_kobo')
                    ->label(__('Earned'))
                    ->formatStateUsing(fn (?int $state): string => Money::fromKobo((int) $state))
                    ->alignRight()
                    ->sortable()
                    ->visibleFrom('md'),

                TextColumn::make('finished_count')
                    ->label(__('Finished'))
                    ->formatStateUsing(fn (Course $record): string => $record->students_count > 0
                        ? sprintf('%d (%d%%)', $record->finished_count, (int) round($record->finished_count / $record->students_count * 100))
                        : '0')
                    ->alignRight()
                    ->visibleFrom('lg'),

                TextColumn::make('certificates_count')
                    ->label(__('Certificates'))
                    ->alignRight()
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(CourseStatus::options()),

                SelectFilter::make('course_category_id')
                    ->label(__('Subject'))
                    ->options(fn (): array => CourseCategory::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (CourseCategory $c): array => [$c->id => $c->pathName()])
                        ->all())
                    ->searchable(),

                SelectFilter::make('level')->label(__('Level'))->options(CourseLevel::options()),

                TernaryFilter::make('is_free')->label(__('Free')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),

                    Action::make('publish')
                        ->label(__('Publish'))
                        ->icon('heroicon-o-check-badge')
                        ->requiresConfirmation()
                        ->visible(fn (Course $record): bool => $record->status !== CourseStatus::Published)
                        ->action(function (Course $record): void {
                            // A course with no lessons would sell an empty
                            // player, which is worse than not being on sale.
                            if ($record->lessons()->count() < 1) {
                                Notification::make()
                                    ->title(__('This course has no lessons'))
                                    ->body(__('Add at least one lesson before publishing.'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $record->publish();

                            Notification::make()
                                ->title(__('Published'))
                                ->body(__('It is on the academy catalogue now.'))
                                ->success()
                                ->send();
                        }),

                    Action::make('archive')
                        ->label(__('Archive'))
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->modalDescription(__('It comes off the catalogue. Everybody who already bought it keeps their access.'))
                        ->visible(fn (Course $record): bool => $record->status === CourseStatus::Published)
                        ->action(function (Course $record): void {
                            $record->forceFill(['status' => CourseStatus::Archived])->save();

                            Notification::make()->title(__('Archived'))->success()->send();
                        }),

                    DeleteAction::make()
                        // Soft-deleted, but a course somebody paid for should
                        // not vanish from their shelf on a stray click.
                        ->before(function (Course $record, DeleteAction $action): void {
                            $students = $record->enrolments()->whereNotNull('enrolled_at')->count();

                            if ($students > 0) {
                                Notification::make()
                                    ->title(__('Students are enrolled on this course'))
                                    ->body(trans_choice(
                                        'One person has bought it and keeps lifetime access. Archive it instead.|:count people have bought it and keep lifetime access. Archive it instead.',
                                        $students,
                                        ['count' => $students],
                                    ))
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }
}
