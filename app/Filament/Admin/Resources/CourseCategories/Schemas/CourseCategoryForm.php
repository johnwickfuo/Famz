<?php

namespace App\Filament\Admin\Resources\CourseCategories\Schemas;

use App\Models\CourseCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CourseCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $state, callable $set, ?CourseCategory $record): void {
                            // Only for a new subject: changing an existing slug
                            // breaks every link anybody has saved to it.
                            if ($record === null) {
                                $set('slug', Str::slug($state));
                            }
                        }),

                    TextInput::make('slug')
                        ->label(__('Slug'))
                        ->required()
                        ->maxLength(140)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('Used in the course catalogue URL.')),

                    Select::make('parent_id')
                        ->label(__('Inside subject'))
                        ->placeholder(__('None — this is a top-level subject'))
                        ->options(fn (?CourseCategory $record): array => CourseCategory::query()
                            // A subject cannot sit inside itself, nor beneath
                            // one of its own children.
                            ->when($record, fn (Builder $query) => $query
                                ->whereKeyNot($record->getKey())
                                ->whereNotIn('id', $record->descendantIds()))
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (CourseCategory $category): array => [
                                $category->id => $category->pathName(),
                            ])
                            ->all())
                        ->searchable()
                        ->preload(),

                    TextInput::make('icon')
                        ->label(__('Icon'))
                        ->placeholder('heroicon-o-academic-cap')
                        ->helperText(__('A Heroicon name, used on the academy home page.'))
                        ->maxLength(64),

                    TextInput::make('sort_order')
                        ->label(__('Sort order'))
                        ->numeric()
                        ->default(0)
                        ->helperText(__('Lower numbers come first.')),

                    Toggle::make('is_active')
                        ->label(__('Active'))
                        ->default(true)
                        ->helperText(__('Hidden from the academy when off. Courses keep their subject.')),

                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
