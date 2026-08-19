<?php

namespace App\Filament\Admin\Resources\Withdrawals\Pages;

use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Resources\Withdrawals\WithdrawalResource;
use App\Models\Withdrawal;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;

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
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    WithdrawalStatus::Requested,
                    WithdrawalStatus::Approved,
                ]))
                ->badge(Withdrawal::query()->awaitingApproval()->count())
                ->badgeColor('warning'),

            'in_flight' => Tab::make(__('On their way'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WithdrawalStatus::Processing)),

            'problems' => Tab::make(__('Problems'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    WithdrawalStatus::Failed,
                    WithdrawalStatus::Rejected,
                ])),

            'all' => Tab::make(__('All')),
        ];
    }
}
