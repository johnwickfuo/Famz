<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Schemas;

use App\Enums\BusinessType;
use App\Enums\SellerStatus;
use App\Models\Category;
use App\Models\SellerProfile;
use App\Support\Nigeria;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SellerProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Application'))
                    ->description(__('What the applicant told us. Editable so an administrator can correct an obvious typo without sending it back.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('business_name')
                            ->label(__('Business name'))
                            ->required()
                            ->maxLength(160),

                        Select::make('business_type')
                            ->label(__('Business type'))
                            ->options(BusinessType::options())
                            ->required(),

                        TextInput::make('cac_number')
                            ->label(__('CAC number'))
                            ->helperText(__('Often empty — most traders in this market are not registered.'))
                            ->maxLength(32),

                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->required()
                            ->maxLength(32),

                        TextInput::make('whatsapp')
                            ->label(__('WhatsApp'))
                            ->tel()
                            ->maxLength(32),

                        Select::make('state')
                            ->label(__('State'))
                            ->options(array_combine(Nigeria::states(), Nigeria::states()))
                            ->searchable()
                            ->required(),

                        TextInput::make('lga')
                            ->label(__('LGA'))
                            ->required()
                            ->maxLength(96),

                        Textarea::make('address')
                            ->label(__('Address'))
                            ->rows(3)
                            ->required()
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label(__('About the business'))
                            ->rows(5)
                            ->required()
                            ->columnSpanFull(),

                        Select::make('categories')
                            ->label(__('Intends to sell in'))
                            ->relationship('categories', 'name')
                            ->multiple()
                            ->preload()
                            ->getOptionLabelFromRecordUsing(fn (Category $record): string => $record->pathName())
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Review'))
                    ->description(__('Use the buttons above to approve, reject or ask for more — they record the decision and email the applicant. These fields are here for reference.'))
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label(__('Status'))
                            ->options(collect(SellerStatus::cases())
                                ->mapWithKeys(fn (SellerStatus $status): array => [$status->value => $status->label()])
                                ->all())
                            ->disabled()
                            ->dehydrated(false),

                        Placeholder::make('reviewed_summary')
                            ->label(__('Reviewed'))
                            ->content(fn (?SellerProfile $record): string => $record?->reviewed_at?->format('j M Y, H:i') ?? __('Not reviewed yet')),

                        Textarea::make('review_notes')
                            ->label(__('Reason or request sent to the applicant'))
                            ->rows(3)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        Toggle::make('auto_approve_products')
                            ->label(__('Skip review on this seller\'s new listings'))
                            ->helperText(__('Leave off and the platform rule applies: listings queue for review until three have been approved, then they go live straight away. Turn it on to vouch for a seller now.'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
