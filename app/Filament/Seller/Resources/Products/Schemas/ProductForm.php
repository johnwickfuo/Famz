<?php

namespace App\Filament\Seller\Resources\Products\Schemas;

use App\Enums\ProductCondition;
use App\Enums\UnitOfMeasure;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Support\Money;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What are you selling?'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Listing name'))
                        ->required()
                        ->maxLength(160)
                        ->placeholder(__('Broiler starter mash, 25kg'))
                        ->columnSpanFull(),

                    Select::make('category_id')
                        ->label(__('Category'))
                        ->required()
                        ->searchable()
                        ->preload()
                        // The full path, because "Feeders" on its own is
                        // ambiguous once the tree has eighty nodes in it.
                        ->options(fn (): array => Category::query()
                            ->active()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Category $category): array => [
                                $category->id => $category->pathName(),
                            ])
                            ->all()),

                    Select::make('condition')
                        ->label(__('Condition'))
                        ->options(ProductCondition::options())
                        ->default(ProductCondition::New->value)
                        ->required(),

                    Textarea::make('description')
                        ->label(__('Description'))
                        ->required()
                        ->rows(6)
                        ->minLength(20)
                        ->maxLength(5000)
                        ->helperText(__('What it is, what condition it is in, and where you deliver.'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Price and stock'))
                ->columns(2)
                ->schema([
                    /*
                     * Sellers type Naira; the database stores kobo. The
                     * conversion happens here rather than in a mutator so the
                     * seller never sees a figure they did not type.
                     */
                    TextInput::make('price_naira')
                        ->label(__('Price'))
                        ->prefix(Money::SIGN)
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->afterStateHydrated(fn (TextInput $component, $state, ?Product $record) => $component->state(
                            $record ? $record->price_kobo / 100 : $state
                        )),

                    TextInput::make('compare_at_price_naira')
                        ->label(__('Was (optional)'))
                        ->prefix(Money::SIGN)
                        ->numeric()
                        ->minValue(0)
                        ->helperText(__('Shows buyers the saving. Leave empty if not on offer.'))
                        ->afterStateHydrated(fn (TextInput $component, $state, ?Product $record) => $component->state(
                            $record?->compare_at_price_kobo ? $record->compare_at_price_kobo / 100 : $state
                        )),

                    Select::make('unit_of_measure')
                        ->label(__('Sold by'))
                        ->options(UnitOfMeasure::options())
                        ->default(UnitOfMeasure::Piece->value)
                        ->required(),

                    TextInput::make('stock_quantity')
                        ->label(__('In stock'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->helperText(__('Set to zero and the listing shows as out of stock instead of disappearing.')),

                    TextInput::make('min_order_quantity')
                        ->label(__('Minimum order'))
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),

                    Toggle::make('is_negotiable')
                        ->label(__('Price is negotiable'))
                        ->helperText(__('Buyers can send you an offer.')),

                    Toggle::make('requires_delivery_quote')
                        ->label(__('Delivery quoted separately'))
                        ->helperText(__('For anything too big or too far for a standard rate.')),
                ]),

            Section::make(__('Handling'))
                ->description(__('Live birds and perishable goods need to say how they get to the buyer.'))
                ->columns(2)
                ->schema([
                    Toggle::make('is_live_animal')
                        ->label(__('This is a live animal or bird'))
                        ->live(),

                    Toggle::make('is_perishable')
                        ->label(__('This is perishable'))
                        ->live(),

                    Textarea::make('handling_note')
                        ->label(__('Handling and delivery note'))
                        ->rows(3)
                        ->maxLength(1000)
                        // Required exactly when the flags say it matters, so a
                        // seller is never asked for it needlessly and never
                        // able to skip it when it counts.
                        ->required(fn (Get $get): bool => (bool) $get('is_live_animal') || (bool) $get('is_perishable'))
                        ->visible(fn (Get $get): bool => (bool) $get('is_live_animal') || (bool) $get('is_perishable'))
                        ->helperText(__('How and when the buyer collects or you deliver — cool of the morning, buyer brings crates, collected within 24 hours.'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Photographs'))
                ->description(__('The first photograph is the one buyers see in the catalogue. Drag to reorder.'))
                ->schema([
                    FileUpload::make('images')
                        ->label(__('Photographs'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->maxFiles(8)
                        ->maxSize(4096)
                        ->disk(config('filesystems.default'))
                        ->directory('products')
                        ->visibility('public')
                        ->columnSpanFull(),
                ]),

            Section::make(__('Options'))
                ->description(__('Sizes or weights of the same thing — a 25kg bag and a 50kg bag, a pullet and a cockerel.'))
                ->collapsed()
                ->schema([
                    Repeater::make('variants')
                        ->label(__('Options'))
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add an option'))
                        ->schema([
                            TextInput::make('name')
                                ->label(__('Option'))
                                ->required()
                                ->maxLength(80)
                                ->placeholder(__('50kg bag')),

                            TextInput::make('price_delta_naira')
                                ->label(__('Price difference'))
                                ->prefix(Money::SIGN)
                                ->numeric()
                                ->default(0)
                                ->helperText(__('Use a minus for cheaper.'))
                                ->afterStateHydrated(fn (TextInput $component, $state, ?ProductVariant $record) => $component->state(
                                    $record ? $record->price_delta_kobo / 100 : ($state ?? 0)
                                )),

                            TextInput::make('stock_quantity')
                                ->label(__('In stock'))
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->required(),
                        ])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::variantToKobo($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::variantToKobo($data)),
                ]),

            Section::make(__('Bulk pricing'))
                ->description(__('Buy more, pay less per unit. Leave empty if you charge one price.'))
                ->collapsed()
                ->schema([
                    Repeater::make('priceTiers')
                        ->label(__('Bulk prices'))
                        ->relationship()
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add a bulk price'))
                        ->schema([
                            TextInput::make('min_quantity')
                                ->label(__('From this quantity'))
                                ->numeric()
                                ->minValue(2)
                                ->required(),

                            TextInput::make('unit_price_naira')
                                ->label(__('Price each'))
                                ->prefix(Money::SIGN)
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->afterStateHydrated(fn (TextInput $component, $state, ?ProductPriceTier $record) => $component->state(
                                    $record ? $record->unit_price_kobo / 100 : $state
                                )),
                        ])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::tierToKobo($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::tierToKobo($data)),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function variantToKobo(array $data): array
    {
        $data['price_delta_kobo'] = Money::toKobo($data['price_delta_naira'] ?? 0);
        unset($data['price_delta_naira']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function tierToKobo(array $data): array
    {
        $data['unit_price_kobo'] = Money::toKobo($data['unit_price_naira'] ?? 0);
        unset($data['unit_price_naira']);

        return $data;
    }
}
