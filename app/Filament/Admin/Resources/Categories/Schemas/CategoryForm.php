<?php

namespace App\Filament\Admin\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CategoryForm
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
                        ->afterStateUpdated(function (string $state, callable $set, ?Category $record): void {
                            // Only suggest a slug for a new node: changing an
                            // existing one would break links people have saved.
                            if ($record === null) {
                                $set('slug', Str::slug($state));
                            }
                        }),

                    TextInput::make('slug')
                        ->label(__('Slug'))
                        ->required()
                        ->maxLength(140)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('Used in the catalogue URL. Changing it breaks saved links.')),

                    Select::make('parent_id')
                        ->label(__('Parent category'))
                        ->placeholder(__('None — this is a top-level branch'))
                        ->options(fn (?Category $record): array => Category::query()
                            // A category cannot be filed under itself, and a
                            // parent cannot be moved beneath its own child.
                            ->when($record, fn (Builder $query) => $query
                                ->whereKeyNot($record->getKey())
                                ->whereNotIn('id', $record->descendantIds()))
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Category $category): array => [
                                $category->id => $category->pathName(),
                            ])
                            ->all())
                        ->searchable()
                        ->preload(),

                    TextInput::make('icon')
                        ->label(__('Icon'))
                        ->placeholder('heroicon-o-sparkles')
                        ->helperText(__('A Heroicon name, used on the catalogue home page.'))
                        ->maxLength(64),

                    TextInput::make('sort_order')
                        ->label(__('Sort order'))
                        ->numeric()
                        ->default(0)
                        ->helperText(__('Lower numbers come first among siblings.')),

                    Toggle::make('is_active')
                        ->label(__('Active'))
                        ->default(true)
                        ->helperText(__('Hidden from the catalogue when off. Existing listings keep their category.')),

                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
