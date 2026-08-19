<?php

namespace App\Filament\Admin\Resources\Courses\RelationManagers;

use App\Filament\Admin\Resources\Courses\Schemas\LessonFields;
use App\Models\CourseModule;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The curriculum.
 *
 * Modules are the table; the lessons inside one are edited with it, in a
 * repeater. That keeps the unit of work small — one module at a time — where a
 * single form holding a forty-lesson course would be a page nobody can save and
 * a video upload nobody dares interrupt.
 */
class ModulesRelationManager extends RelationManager
{
    protected static string $relationship = 'modules';

    protected static ?string $title = 'Curriculum';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label(__('Module title'))
                ->required()
                ->maxLength(160)
                ->columnSpanFull(),

            Textarea::make('summary')
                ->label(__('What this module covers'))
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull(),

            TextInput::make('sort_order')
                ->label(__('Order'))
                ->numeric()
                ->default(0),

            Repeater::make('lessons')
                ->label(__('Lessons'))
                ->relationship()
                ->orderColumn('sort_order')
                ->reorderable()
                ->collapsible()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->addActionLabel(__('Add a lesson'))
                ->defaultItems(0)
                ->columns(4)
                ->columnSpanFull()
                ->schema(LessonFields::make()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->withCount('lessons'))
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Module'))
                    ->description(fn (CourseModule $record): ?string => $record->summary)
                    ->wrap(),

                TextColumn::make('lessons_count')
                    ->label(__('Lessons'))
                    ->alignRight(),

                TextColumn::make('sort_order')
                    ->label(__('Order'))
                    ->alignRight()
                    ->visibleFrom('md'),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Add a module')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->label(__('Edit module and lessons')),
                    DeleteAction::make()
                        ->modalDescription(__('The lessons inside it go too, files and all.')),
                ]),
            ]);
    }
}
