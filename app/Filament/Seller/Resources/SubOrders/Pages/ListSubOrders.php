<?php

namespace App\Filament\Seller\Resources\SubOrders\Pages;

use App\Enums\SubOrderStatus;
use App\Filament\Seller\Resources\SubOrders\SubOrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSubOrders extends ListRecords
{
    protected static string $resource = SubOrderResource::class;

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
            'to_do' => Tab::make(__('Needs you'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    SubOrderStatus::Pending,
                    SubOrderStatus::Accepted,
                    SubOrderStatus::Shipped,
                ]))
                ->badge(static::getResource()::getEloquentQuery()
                    ->where('status', SubOrderStatus::Pending)
                    ->count())
                ->badgeColor('warning'),

            'delivered' => Tab::make(__('Delivered'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    SubOrderStatus::Delivered,
                    SubOrderStatus::Settled,
                ])),

            'problems' => Tab::make(__('Problems'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    SubOrderStatus::Rejected,
                    SubOrderStatus::Disputed,
                    SubOrderStatus::Refunded,
                    SubOrderStatus::Cancelled,
                ])),

            'all' => Tab::make(__('All')),
        ];
    }
}
