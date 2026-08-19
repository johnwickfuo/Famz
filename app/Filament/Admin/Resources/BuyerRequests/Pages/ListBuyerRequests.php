<?php

namespace App\Filament\Admin\Resources\BuyerRequests\Pages;

use App\Enums\BuyerRequestStatus;
use App\Filament\Admin\Resources\BuyerRequests\BuyerRequestResource;
use App\Models\BuyerRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBuyerRequests extends ListRecords
{
    protected static string $resource = BuyerRequestResource::class;

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
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BuyerRequestStatus::PendingApproval))
                ->badge(BuyerRequest::query()->where('status', BuyerRequestStatus::PendingApproval)->count())
                ->badgeColor('warning'),

            'live' => Tab::make(__('On the board'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BuyerRequestStatus::Open)),

            'done' => Tab::make(__('Finished'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    BuyerRequestStatus::OfferAccepted,
                    BuyerRequestStatus::Closed,
                    BuyerRequestStatus::Expired,
                    BuyerRequestStatus::Rejected,
                ])),

            'all' => Tab::make(__('All')),
        ];
    }
}
