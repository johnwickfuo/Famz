<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Pages;

use App\Enums\SellerStatus;
use App\Filament\Admin\Resources\SellerProfiles\SellerProfileResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSellerProfiles extends ListRecords
{
    protected static string $resource = SellerProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * The queue an administrator actually works through, with the waiting
     * applications first.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'waiting' => Tab::make(__('Waiting on us'))
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingReview())
                ->badge(static::getResource()::getModel()::query()->awaitingReview()->count())
                ->badgeColor('warning'),

            'approved' => Tab::make(__('Approved'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SellerStatus::Approved)),

            'rejected' => Tab::make(__('Rejected'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SellerStatus::Rejected)),

            'all' => Tab::make(__('All')),
        ];
    }
}
