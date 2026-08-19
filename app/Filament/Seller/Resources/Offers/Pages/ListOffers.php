<?php

namespace App\Filament\Seller\Resources\Offers\Pages;

use App\Enums\OfferStatus;
use App\Filament\Seller\Resources\Offers\OfferResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'waiting' => Tab::make(__('Waiting on you'))
                ->modifyQueryUsing(fn (Builder $query) => $query->awaiting(auth()->user()))
                ->badge(OfferResource::getEloquentQuery()->awaiting(auth()->user())->count())
                ->badgeColor('warning'),

            'sent' => Tab::make(__('You are waiting'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->open()
                    ->where('initiator_id', auth()->id())),

            'agreed' => Tab::make(__('Agreed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OfferStatus::Accepted)),

            'all' => Tab::make(__('All')),
        ];
    }
}
