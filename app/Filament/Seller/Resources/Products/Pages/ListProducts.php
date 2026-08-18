<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Enums\ProductStatus;
use App\Filament\Seller\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New listing')),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),

            'live' => Tab::make(__('Live'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProductStatus::Active)),

            'pending' => Tab::make(__('Awaiting review'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProductStatus::PendingReview)),

            'out_of_stock' => Tab::make(__('Out of stock'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProductStatus::OutOfStock)),

            'drafts' => Tab::make(__('Drafts'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProductStatus::Draft)),

            'rejected' => Tab::make(__('Rejected'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProductStatus::Rejected)),
        ];
    }
}
