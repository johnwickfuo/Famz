<?php

namespace App\Filament\Admin\Resources\Disputes\Pages;

use App\Enums\DisputeStatus;
use App\Filament\Admin\Resources\Disputes\DisputeResource;
use App\Models\Dispute;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

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
            'live' => Tab::make(__('Money frozen'))
                ->modifyQueryUsing(fn (Builder $query) => $query->live())
                ->badge(Dispute::query()->live()->count())
                ->badgeColor('danger'),

            'untouched' => Tab::make(__('Nobody has looked'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DisputeStatus::Open)),

            'settled' => Tab::make(__('Settled'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('status', [
                    DisputeStatus::Open,
                    DisputeStatus::UnderReview,
                ])),

            'all' => Tab::make(__('All')),
        ];
    }
}
