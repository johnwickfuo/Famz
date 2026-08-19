<?php

namespace App\Filament\Admin\Resources\CourseCategories\Tables;

use App\Models\CourseCategory;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CourseCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent')->withCount('courses'))
            ->columns([
                // The full path, not the bare name: "Records" means nothing on
                // its own once the tree has depth.
                TextColumn::make('name')
                    ->label(__('Subject'))
                    ->formatStateUsing(fn (CourseCategory $record): string => $record->pathName())
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('courses_count')
                    ->label(__('Courses'))
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label(__('Order'))
                    ->alignRight()
                    ->sortable()
                    ->visibleFrom('md'),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                Filter::make('roots')
                    ->label(__('Top-level only'))
                    ->query(fn (Builder $query) => $query->whereNull('parent_id')),

                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        // A subject with courses filed under it cannot go: the
                        // foreign key restricts it, and a 500 is a worse answer
                        // than a sentence.
                        ->before(function (CourseCategory $record, DeleteAction $action): void {
                            $ids = [$record->getKey(), ...$record->descendantIds()];

                            $count = CourseCategory::query()
                                ->whereIn('id', $ids)
                                ->withCount('courses')
                                ->get()
                                ->sum('courses_count');

                            if ($count > 0) {
                                Notification::make()
                                    ->title(__('This subject still has courses'))
                                    ->body(trans_choice(
                                        'One course is filed here. Move it first.|:count courses are filed here. Move them first.',
                                        $count,
                                        ['count' => $count],
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
