<?php

namespace App\Filament\Admin\Resources\Specialisations\Schemas;

use App\Models\Specialisation;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SpecialisationForm
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
                        ->afterStateUpdated(function (string $state, callable $set, ?Specialisation $record): void {
                            if ($record === null) {
                                $set('slug', Str::slug($state));
                            }
                        }),

                    TextInput::make('slug')
                        ->label(__('Slug'))
                        ->required()
                        ->maxLength(140)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('What the AI layer names when it chooses this tag.')),

                    TextInput::make('sector')
                        ->label(__('Sector'))
                        ->required()
                        ->maxLength(64)
                        ->datalist(fn (): array => Specialisation::query()
                            ->distinct()
                            ->orderBy('sector')
                            ->pluck('sector')
                            ->all())
                        ->helperText(__('Groups it on the forms — poultry, livestock, crops, business.')),

                    TextInput::make('sort_order')
                        ->label(__('Order'))
                        ->numeric()
                        ->default(0),

                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),

                    /*
                     * The words a farmer would actually type. Read directly by
                     * the fallback matcher, so "my chicks are dying" belongs
                     * here and "poultry husbandry optimisation" does not.
                     */
                    Textarea::make('keywords_text')
                        ->label(__('Words a farmer would use'))
                        ->rows(3)
                        ->helperText(__('One per line. These are what the matcher reads when the AI is unavailable, so write them the way somebody would actually say it.'))
                        ->afterStateHydrated(fn (Textarea $component, ?Specialisation $record) => $component->state(
                            implode("\n", $record?->keywords ?? []),
                        ))
                        ->dehydrated(false)
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->label(__('In use'))
                        ->default(true)
                        ->helperText(__('Turned off, it stops being offered and stops being matched on. Mentors keep it.')),
                ]),
        ]);
    }
}
