<?php

namespace App\Filament\Seller\Pages;

use App\Filament\Seller\Widgets\EarningsOverview;
use App\Filament\Seller\Widgets\OrdersByStatus;
use App\Filament\Seller\Widgets\RevenueByMonth;
use App\Services\Payouts\WithdrawalService;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * What a seller has made.
 *
 * Every figure on this page is summed from the ledger when the page loads.
 * Nothing is cached and nothing is stored, so what a seller sees is always
 * exactly what their entries add up to — which is the whole reason there is no
 * balance column anywhere in this application.
 */
class Earnings extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Earnings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Earnings');
    }

    public function getSubheading(): ?string
    {
        $requestable = app(WithdrawalService::class)->requestableBalance(auth()->user());

        return $requestable > 0
            ? __('You have money ready to take out — see Payouts.')
            : __('Every figure here is worked out from your statement, live.');
    }

    /**
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        return [
            EarningsOverview::class,
            RevenueByMonth::class,
            OrdersByStatus::class,
        ];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 2;
    }
}
