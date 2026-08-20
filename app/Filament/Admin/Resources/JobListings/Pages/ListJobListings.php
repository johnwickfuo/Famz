<?php

namespace App\Filament\Admin\Resources\JobListings\Pages;

use App\Enums\JobListingStatus;
use App\Filament\Admin\Resources\JobListings\JobListingResource;
use App\Models\JobListing;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListJobListings extends ListRecords
{
    protected static string $resource = JobListingResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'live' => Tab::make(__('Live'))
                ->modifyQueryUsing(fn (Builder $query) => $query->onBoard())
                ->badge(JobListing::query()->onBoard()->count()),

            'drafts' => Tab::make(__('Drafts'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', JobListingStatus::Draft)),

            'closed' => Tab::make(__('Closed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    JobListingStatus::Filled,
                    JobListingStatus::Closed,
                    JobListingStatus::Expired,
                ])),

            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'live';
    }
}
