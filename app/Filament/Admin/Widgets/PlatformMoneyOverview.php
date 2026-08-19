<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Dispute;
use App\Services\Reporting\PlatformFinances;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The platform's money at a glance.
 *
 * Held commission is shown next to earned commission rather than folded into
 * it: it is revenue earned and not yet keepable, and hiding it would make the
 * platform look poorer than it is while overstating what it can spend.
 */
class PlatformMoneyOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -20;

    protected function getColumns(): int
    {
        return 3;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $finances = app(PlatformFinances::class);

        $thisMonth = $finances->commissionBetween(now()->startOfMonth(), null);
        $lastMonth = $finances->commissionBetween(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        );

        $pending = $finances->pendingWithdrawals();

        return [
            Stat::make(__('Commission this month'), Money::fromKobo($thisMonth))
                ->description($this->movement($thisMonth, $lastMonth))
                ->descriptionIcon($thisMonth >= $lastMonth ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($thisMonth >= $lastMonth ? 'success' : 'danger')
                ->chart(array_map(
                    fn (int $kobo): float => $kobo / 100,
                    $finances->commissionByMonth(6)->values()->all(),
                )),

            Stat::make(__('Commission all time'), Money::fromKobo($finances->commissionBetween(null, null)))
                ->description(__(':amount of it still held in escrow.', [
                    'amount' => Money::fromKobo($finances->commissionHeld()),
                ]))
                ->color('gray'),

            Stat::make(__('Owed to sellers'), Money::fromKobo($finances->owedToSellers()))
                ->description(__('Balances and escrow the platform is holding for other people.'))
                ->color('warning')
                ->icon('heroicon-o-lock-closed'),

            Stat::make(__('Payouts in flight'), Money::fromKobo($pending['amount_kobo']))
                ->description(trans_choice(
                    ':count payout waiting|:count payouts waiting',
                    $pending['count'],
                    ['count' => $pending['count']],
                ))
                ->color($pending['count'] > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-arrow-up-tray'),

            Stat::make(__('Open disputes'), (string) Dispute::query()->live()->count())
                ->description(__('Each one is money frozen for both sides.'))
                ->color(Dispute::query()->live()->exists() ? 'danger' : 'gray')
                ->icon('heroicon-o-scale'),

            Stat::make(__('Platform balance'), Money::fromKobo($finances->platformBalance()))
                ->description(__('Commission earned and not spent.'))
                ->color('primary')
                ->icon('heroicon-o-banknotes'),
        ];
    }

    private function movement(int $now, int $before): string
    {
        if ($before === 0) {
            return $now > 0 ? __('First month with any commission.') : __('Nothing yet this month.');
        }

        $change = (int) round((($now - $before) / $before) * 100);

        return __(':percent% against last month (:amount).', [
            'percent' => ($change >= 0 ? '+' : '').$change,
            'amount' => Money::fromKobo($before),
        ]);
    }
}
