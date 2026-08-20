<?php

namespace App\Filament\Admin\Resources\BreedStandards\Schemas;

use App\Models\BreedStandard;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BreedStandardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Which bird, which week'))
                ->description(__('One row per week. The assistant reads the last row at or below the week asked about, which is how printed guides are read too.'))
                ->columns(2)
                ->schema([
                    TextInput::make('species')
                        ->label(__('Species'))
                        ->required()
                        ->maxLength(40)
                        ->default('Chicken')
                        ->datalist(fn (): array => self::distinct('species')),

                    TextInput::make('breed')
                        ->label(__('Breed'))
                        ->required()
                        ->maxLength(80)
                        ->datalist(fn (): array => self::distinct('breed'))
                        // The classifier reads this vocabulary out of the
                        // database, so adding a breed here is what makes the
                        // assistant recognise it in a question — no code
                        // change, no deployment.
                        ->helperText(__('Exactly as a farmer would type it. Adding a breed here is what teaches the assistant to recognise it.')),

                    TextInput::make('production_type')
                        ->label(__('Production type'))
                        ->required()
                        ->maxLength(40)
                        ->datalist(fn (): array => self::distinct('production_type'))
                        ->helperText(__('Broiler, layer, dual purpose. A question with no weeks named sums the whole cycle for a broiler and asks for an age for a layer.')),

                    TextInput::make('week_number')
                        ->label(__('Week'))
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(120),
                ]),

            Section::make(__('The figures'))
                ->description(__('Everything here is quoted to farmers verbatim, with the source below attached. Nothing is estimated and nothing is rounded on the way out.'))
                ->columns(2)
                ->schema([
                    TextInput::make('avg_feed_g_per_bird_per_day')
                        ->label(__('Feed, grams per bird per day'))
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix('g')
                        ->helperText(__('The flock total is worked out from this, so it is the only feed figure that has to be right.')),

                    TextInput::make('target_weight_g')
                        ->label(__('Target live weight'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('g')
                        ->helperText(__('Leave empty if the guide does not give one. An empty cell is better than a guess.')),

                    TextInput::make('water_multiplier')
                        ->label(__('Water, as a multiple of feed'))
                        ->required()
                        ->numeric()
                        ->minValue(0.5)
                        ->maxValue(6)
                        ->default(2.0)
                        ->helperText(__('Birds drink roughly twice what they eat, more in heat. In this climate the multiplier is the figure that actually varies.')),

                    TextInput::make('cumulative_feed_kg')
                        ->label(__('Cumulative feed to this week, kg per bird'))
                        ->numeric()
                        ->minValue(0)
                        ->suffix('kg')
                        ->helperText(__('Optional. The assistant adds up the daily figures itself rather than trusting this, so a stale value here cannot produce a wrong answer.')),
                ]),

            Section::make(__('Where it came from'))
                ->columns(1)
                ->schema([
                    TextInput::make('source')
                        ->label(__('Source'))
                        ->required()
                        ->maxLength(160)
                        ->datalist(fn (): array => self::distinct('source'))
                        // Not decoration. This string is printed under the
                        // answer a farmer reads, so "Aviagen Ross 308 broiler
                        // management guide" is useful and "internet" is not.
                        ->helperText(__('Printed under the answer the farmer reads. Name the publication, not "the internet".')),

                    Textarea::make('notes')
                        ->label(__('Notes'))
                        ->rows(2)
                        ->maxLength(500),

                    Toggle::make('is_active')
                        ->label(__('In use'))
                        ->default(true)
                        ->helperText(__('Turned off, the assistant stops quoting this row. Prefer this to deleting: a row that has been quoted is part of the record.')),
                ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function distinct(string $column): array
    {
        return BreedStandard::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
