<?php

namespace App\Filament\Admin\Resources\JobRatings\Pages;

use App\Enums\RatingStatus;
use App\Filament\Admin\Resources\JobRatings\JobRatingResource;
use App\Models\JobRating;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListJobRatings extends ListRecords
{
    protected static string $resource = JobRatingResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'waiting' => Tab::make(__('Waiting'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', RatingStatus::Pending))
                ->badge(JobRating::query()->where('status', RatingStatus::Pending)->count())
                ->badgeColor('warning'),

            'published' => Tab::make(__('Published'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', RatingStatus::Approved)),

            'refused' => Tab::make(__('Not published'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', RatingStatus::Rejected)),

            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'waiting';
    }
}
