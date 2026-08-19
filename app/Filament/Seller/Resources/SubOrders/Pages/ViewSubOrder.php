<?php

namespace App\Filament\Seller\Resources\SubOrders\Pages;

use App\Filament\Seller\Actions\FulfilmentActions;
use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use App\Models\SubOrder;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewSubOrder extends ViewRecord
{
    protected static string $resource = SubOrderResource::class;

    protected function getHeaderActions(): array
    {
        return FulfilmentActions::all();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What to send'))
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('product_name')
                                ->label(__('Item'))
                                ->formatStateUsing(fn ($state, $record): string => $record->label()),
                            TextEntry::make('quantity')->label(__('Quantity')),
                            TextEntry::make('line_total_kobo')
                                ->label(__('Line total'))
                                ->formatStateUsing(fn (int $state): string => Money::fromKobo($state)),
                        ]),
                ]),

            Section::make(__('Where it goes'))
                ->columns(2)
                ->schema([
                    TextEntry::make('delivery_method')
                        ->label(__('Delivery'))
                        ->formatStateUsing(fn ($state): string => $state->label()),

                    TextEntry::make('delivery_fee_kobo')
                        ->label(__('Delivery fee'))
                        ->formatStateUsing(fn (int $state): string => Money::fromKobo($state)),

                    TextEntry::make('order.delivery_name')->label(__('Buyer')),
                    TextEntry::make('order.delivery_phone')->label(__('Phone')),

                    TextEntry::make('order.delivery_address')
                        ->label(__('Address'))
                        ->formatStateUsing(fn (?string $state, SubOrder $record): string => implode(', ', array_filter([
                            $record->order->delivery_address,
                            $record->order->delivery_lga,
                            $record->order->delivery_state,
                        ])))
                        ->columnSpanFull(),

                    TextEntry::make('order.delivery_note')
                        ->label(__('Note from the buyer'))
                        ->placeholder(__('None'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Your money'))
                ->columns(3)
                ->schema([
                    TextEntry::make('subtotal_kobo')
                        ->label(__('Goods'))
                        ->formatStateUsing(fn (int $state): string => Money::fromKobo($state)),

                    TextEntry::make('commission_amount_kobo')
                        ->label(__('Platform commission'))
                        ->formatStateUsing(fn (int $state, SubOrder $record): string => Money::fromKobo($state)
                            .' ('.rtrim(rtrim((string) $record->commission_percent_snapshot, '0'), '.').'%)'),

                    TextEntry::make('seller_payout_amount_kobo')
                        ->label(__('You receive'))
                        ->weight('bold')
                        ->formatStateUsing(fn (int $state): string => Money::fromKobo($state)),

                    TextEntry::make('rejection_reason')
                        ->label(__('Reason given'))
                        ->placeholder(__('None'))
                        ->visible(fn (SubOrder $record): bool => filled($record->rejection_reason))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
