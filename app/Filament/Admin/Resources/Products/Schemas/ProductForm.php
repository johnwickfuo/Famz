<?php

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Models\Product;
use App\Support\Money;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A reviewer's view of a listing. Read-mostly: the decision is made with the
 * buttons in the header, not by editing fields.
 */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Listing'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->columnSpanFull(),

                    Placeholder::make('seller_summary')
                        ->label(__('Seller'))
                        ->content(fn (Product $record): string => $record->seller->business_name.' — '.$record->seller->location()),

                    Placeholder::make('category_summary')
                        ->label(__('Category'))
                        ->content(fn (Product $record): string => $record->category?->pathName() ?? '—'),

                    Placeholder::make('price_summary')
                        ->label(__('Price'))
                        ->content(fn (Product $record): string => Money::fromKobo($record->price_kobo).' '.$record->unit_of_measure->label()),

                    Placeholder::make('stock_summary')
                        ->label(__('Stock'))
                        ->content(fn (Product $record): string => (string) $record->stock_quantity),

                    Textarea::make('description')->label(__('Description'))->rows(6)->columnSpanFull(),
                ]),

            Section::make(__('Handling'))
                ->description(__('Live animals and perishable goods must state how they reach the buyer.'))
                ->schema([
                    Placeholder::make('flags_summary')
                        ->label(__('Flags'))
                        ->content(fn (Product $record): string => collect([
                            $record->is_live_animal ? __('Live animal') : null,
                            $record->is_perishable ? __('Perishable') : null,
                        ])->filter()->implode(', ') ?: __('Neither')),

                    Textarea::make('handling_note')
                        ->label(__('Handling note'))
                        ->rows(3)
                        ->required(fn (Product $record): bool => $record->needsHandlingNote())
                        ->columnSpanFull(),
                ]),

            Section::make(__('Review'))
                ->schema([
                    Textarea::make('review_notes')
                        ->label(__('Reason last sent to the seller'))
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false),
                ]),
        ]);
    }
}
