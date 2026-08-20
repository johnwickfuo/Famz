<?php

namespace App\Filament\Admin\Resources\QuotationRequests\Pages;

use App\Enums\QuotationRequestStatus;
use App\Filament\Admin\Resources\QuotationRequests\QuotationRequestResource;
use App\Models\QuotationRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListQuotationRequests extends ListRecords
{
    protected static string $resource = QuotationRequestResource::class;

    /**
     * Tabs by what somebody is about to do.
     *
     * "To write" comes first and carries its own count, because it is the only
     * tab representing money already taken for work not yet delivered. Labels
     * are a word or two so the whole strip fits on a laptop — a tab that has
     * scrolled off the right-hand edge is a tab nobody clicks.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'to_write' => Tab::make(__('To write'))
                ->modifyQueryUsing(fn (Builder $query) => $query->workable())
                ->badge(QuotationRequest::query()->workable()->count())
                ->badgeColor('warning'),

            'unpaid' => Tab::make(__('Unpaid'))
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingStudyFee())
                ->badge(QuotationRequest::query()->awaitingStudyFee()->count()),

            'sent' => Tab::make(__('Sent'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', QuotationRequestStatus::QuoteSent))
                ->badge(QuotationRequest::query()
                    ->where('status', QuotationRequestStatus::QuoteSent)
                    ->count()),

            'open' => Tab::make(__('Everything open'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', QuotationRequestResource::openStatuses())),

            'won' => Tab::make(__('Won'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', QuotationRequestStatus::AcceptedOffline)),

            'closed' => Tab::make(__('Closed'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', [
                        QuotationRequestStatus::AcceptedOffline,
                        QuotationRequestStatus::Declined,
                        QuotationRequestStatus::Expired,
                    ])),

            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        // Open on the work somebody has already been paid for, if there is any.
        return QuotationRequest::query()->workable()->exists() ? 'to_write' : 'open';
    }
}
