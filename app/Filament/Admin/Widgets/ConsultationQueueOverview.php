<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Models\Consultation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The consultation promise, on the dashboard.
 *
 * Overdue first and in red, because it is the only number here that represents
 * a promise already broken. "Due soon" sits beside it so somebody can act
 * before it turns red rather than after.
 */
class ConsultationQueueOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -15;

    /**
     * How far ahead "soon" reaches. Roughly a working morning: long enough to
     * be actionable, short enough that the number means something.
     */
    private const SOON_HOURS = 6;

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $waiting = Consultation::query()->where('status', ConsultationStatus::Submitted);

        $overdue = (clone $waiting)
            ->whereNotNull('response_due_at')
            ->where('response_due_at', '<', now())
            ->count();

        $dueSoon = (clone $waiting)
            ->whereNotNull('response_due_at')
            ->whereBetween('response_due_at', [now(), now()->addHours(self::SOON_HOURS)])
            ->count();

        $urgentWaiting = (clone $waiting)->where('tier', ConsultationTier::Urgent)->count();

        $unpaid = Consultation::query()
            ->whereIn('status', [ConsultationStatus::Quoted, ConsultationStatus::AwaitingPayment])
            ->count();

        return [
            Stat::make(__('Late'), (string) $overdue)
                ->description($overdue > 0
                    ? __('Past the time we promised. Ring them.')
                    : __('Nobody is waiting past their time.'))
                ->descriptionIcon($overdue > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdue > 0 ? 'danger' : 'success'),

            Stat::make(__('Due within :hours hours', ['hours' => self::SOON_HOURS]), (string) $dueSoon)
                ->description(__('Still in time, if somebody moves.'))
                ->color($dueSoon > 0 ? 'warning' : 'gray'),

            Stat::make(__('Urgent, unanswered'), (string) $urgentWaiting)
                ->description(__('People who paid extra for speed.'))
                ->color($urgentWaiting > 0 ? 'warning' : 'gray'),

            Stat::make(__('Quoted, unpaid'), (string) $unpaid)
                ->description(__('Priced and waiting on the client.'))
                ->color('gray'),
        ];
    }
}
