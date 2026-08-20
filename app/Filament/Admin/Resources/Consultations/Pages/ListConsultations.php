<?php

namespace App\Filament\Admin\Resources\Consultations\Pages;

use App\Enums\ConsultationStatus;
use App\Filament\Admin\Resources\Consultations\ConsultationResource;
use App\Models\Consultation;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListConsultations extends ListRecords
{
    protected static string $resource = ConsultationResource::class;

    /**
     * Tabs by what somebody is about to do, not by every status in the enum.
     *
     * "Late" comes first and carries its own count, because it is the only tab
     * that represents a broken promise.
     *
     * Labels are kept to a word or two so the whole strip fits on a laptop.
     * A tab that has scrolled off the right-hand edge is a tab nobody clicks.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'queue' => Tab::make(__('Queue'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', ConsultationResource::openStatuses()))
                ->badge(Consultation::query()->whereIn('status', ConsultationResource::openStatuses())->count()),

            'late' => Tab::make(__('Late'))
                ->modifyQueryUsing(fn (Builder $query) => $query->overdue())
                ->badge(Consultation::query()->overdue()->count())
                ->badgeColor('danger'),

            'to_call' => Tab::make(__('To call'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', ConsultationStatus::Submitted))
                ->badge(Consultation::query()->where('status', ConsultationStatus::Submitted)->count()),

            'to_quote' => Tab::make(__('To price'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', ConsultationStatus::Contacted))
                ->badge(Consultation::query()->where('status', ConsultationStatus::Contacted)->count()),

            'unpaid' => Tab::make(__('Unpaid'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', [ConsultationStatus::Quoted, ConsultationStatus::AwaitingPayment]))
                ->badge(Consultation::query()
                    ->whereIn('status', [ConsultationStatus::Quoted, ConsultationStatus::AwaitingPayment])
                    ->count()),

            'working' => Tab::make(__('Working'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', [ConsultationStatus::Paid, ConsultationStatus::InProgress]))
                ->badge(Consultation::query()
                    ->whereIn('status', [ConsultationStatus::Paid, ConsultationStatus::InProgress])
                    ->count()),

            'closed' => Tab::make(__('Closed'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereIn('status', [ConsultationStatus::Completed, ConsultationStatus::Cancelled])),

            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        // Open on whatever is late, if anything is.
        return Consultation::query()->overdue()->exists() ? 'late' : 'queue';
    }
}
