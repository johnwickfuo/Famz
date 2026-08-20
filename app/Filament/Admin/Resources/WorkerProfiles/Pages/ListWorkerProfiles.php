<?php

namespace App\Filament\Admin\Resources\WorkerProfiles\Pages;

use App\Filament\Admin\Resources\WorkerProfiles\WorkerProfileResource;
use App\Models\WorkerProfile;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWorkerProfiles extends ListRecords
{
    protected static string $resource = WorkerProfileResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'looking' => Tab::make(__('Looking for work'))
                ->modifyQueryUsing(fn (Builder $query) => $query->openToWork())
                ->badge(WorkerProfile::query()->openToWork()->count()),

            'not_looking' => Tab::make(__('Not looking'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->where('is_open_to_work', false)),

            'suspended' => Tab::make(__('Taken down'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),

            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'looking';
    }
}
