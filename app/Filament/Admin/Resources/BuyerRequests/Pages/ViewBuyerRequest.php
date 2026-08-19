<?php

namespace App\Filament\Admin\Resources\BuyerRequests\Pages;

use App\Filament\Admin\Actions\BuyerRequestActions;
use App\Filament\Admin\Resources\BuyerRequests\BuyerRequestResource;
use App\Models\BuyerRequest;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class ViewBuyerRequest extends ViewRecord
{
    protected static string $resource = BuyerRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return __(':reference — posted by :who, :when', [
            'reference' => $record->reference,
            'who' => $record->buyer?->displayName() ?? __('somebody'),
            'when' => $record->created_at->diffForHumans(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return BuyerRequestActions::all();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('description')
                    ->label(__('What they wrote'))
                    ->columnSpanFull()
                    ->prose(),

                TextEntry::make('quantity')
                    ->label(__('Wanted'))
                    ->formatStateUsing(fn ($state, BuyerRequest $record): string => $state.' '.$record->unit),

                TextEntry::make('budget')
                    ->label(__('Budget'))
                    ->state(fn (BuyerRequest $record): ?string => $record->budgetLabel())
                    ->placeholder(__('None given')),

                TextEntry::make('category.name')->label(__('Category')),

                TextEntry::make('location')
                    ->label(__('Delivered to'))
                    ->state(fn (BuyerRequest $record): string => $record->location()),

                TextEntry::make('needed_by')
                    ->label(__('Needed by'))
                    ->date('j F Y')
                    ->placeholder(__('No date given')),

                TextEntry::make('accepts_partial_fulfilment')
                    ->label(__('Split between sellers'))
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No, all of it')),

                TextEntry::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->label()),

                TextEntry::make('expires_at')
                    ->label(__('Closes'))
                    ->dateTime('j F Y, H:i')
                    ->placeholder(__('Not published yet')),

                TextEntry::make('rejection_reason')
                    ->label(__('Why it was refused'))
                    ->columnSpanFull()
                    ->prose()
                    ->visible(fn (BuyerRequest $record): bool => filled($record->rejection_reason)),
            ]),

            Section::make(__('Photographs'))
                ->visible(fn (BuyerRequest $record): bool => filled($record->images))
                ->schema([
                    ImageEntry::make('images')
                        ->hiddenLabel()
                        ->disk('public')
                        ->height(160),
                ]),
        ]);
    }
}
