<?php

namespace App\Filament\Mentor\Resources\Packages\Schemas;

use App\Enums\BillingInterval;
use App\Enums\BillingType;
use App\Models\MentorshipPackage;
use App\Support\Money;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What are you offering?'))
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label(__('Name it'))
                        ->required()
                        ->maxLength(160)
                        ->placeholder(__('One-hour farm review call'))
                        ->columnSpanFull(),

                    Textarea::make('description')
                        ->label(__('What the client gets'))
                        ->required()
                        ->rows(4)
                        ->maxLength(2000)
                        ->helperText(__('Be specific. "Two hours on the phone and a written plan" sells; "consultancy" does not.'))
                        ->columnSpanFull(),

                    TextInput::make('duration_description')
                        ->label(__('How long'))
                        ->maxLength(120)
                        ->placeholder(__('1 hour, or ongoing')),

                    TextInput::make('sessions_included')
                        ->label(__('How many sessions'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(200),

                    Repeater::make('deliverables')
                        ->label(__('What they walk away with'))
                        ->simple(TextInput::make('item')->required()->maxLength(200))
                        ->addActionLabel(__('Add one'))
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ]),

            Section::make(__('Price'))
                ->columns(2)
                ->schema([
                    Select::make('billing_type')
                        ->label(__('How you charge'))
                        ->options(BillingType::options())
                        ->default(BillingType::OneTime->value)
                        ->required()
                        ->live(),

                    Select::make('billing_interval')
                        ->label(__('Every'))
                        ->options(BillingInterval::options())
                        ->required(fn (Get $get): bool => $get('billing_type') === BillingType::Periodic->value)
                        ->visible(fn (Get $get): bool => $get('billing_type') === BillingType::Periodic->value)
                        ->helperText(__('The client is billed once per period, and you are paid for each period they confirm.')),

                    TextInput::make('price_naira')
                        ->label(fn (Get $get): string => $get('billing_type') === BillingType::Periodic->value
                            ? __('Price per period')
                            : __('Price'))
                        ->prefix(Money::SIGN)
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->afterStateHydrated(fn (TextInput $component, $state, ?MentorshipPackage $record) => $component->state(
                            $record !== null ? $record->price_kobo / 100 : $state,
                        )),

                    Toggle::make('is_active')
                        ->label(__('Offered'))
                        ->default(true)
                        ->helperText(__('Turn it off to stop new clients choosing it. Anybody already on it is unaffected.')),

                    TextInput::make('sort_order')
                        ->label(__('Order'))
                        ->numeric()
                        ->default(0),
                ]),
        ]);
    }
}
