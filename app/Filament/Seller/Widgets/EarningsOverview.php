<?php

namespace App\Filament\Seller\Widgets;

use App\Enums\LedgerType;
use App\Enums\SubOrderStatus;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\SubOrder;
use App\Models\WalletTransaction;
use App\Services\Payouts\WithdrawalService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The five numbers a seller actually wants.
 *
 * Every one is summed from the ledger at the moment the page loads. Nothing is
 * cached and nothing is stored, so what a seller sees is always exactly what
 * their entries add up to.
 */
class EarningsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 3;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $user = auth()->user();
        $wallet = app(WalletService::class);
        $withdrawals = app(WithdrawalService::class);

        $available = $wallet->availableBalance($user);
        $requestable = $withdrawals->requestableBalance($user);
        $reserved = $available - $requestable;

        $commission = abs((int) WalletTransaction::query()
            ->whereIn('sub_order_id', SubOrder::query()
                ->forSeller(ProductResource::currentSeller())
                ->select('id'))
            ->where('type', LedgerType::Commission)
            ->sum('amount_kobo'));

        return [
            Stat::make(__('Ready to withdraw'), Money::fromKobo($requestable))
                ->description($reserved > 0
                    ? __(':amount is already spoken for by a payout you asked for.', [
                        'amount' => Money::fromKobo($reserved),
                    ])
                    : __('Yours to take out whenever you like.'))
                ->color($requestable > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-banknotes'),

            Stat::make(__('Held until delivery'), Money::fromKobo($wallet->heldBalance($user)))
                ->description(__('Released when the buyer confirms, or automatically afterwards.'))
                ->color('warning')
                ->icon('heroicon-o-lock-closed'),

            Stat::make(__('Sold altogether'), Money::fromKobo($wallet->lifetimeEarnings($user)))
                ->description(__('Everything you have earned, before any payouts.'))
                ->icon('heroicon-o-chart-bar'),

            Stat::make(__('Commission paid'), Money::fromKobo($commission))
                ->description(__('The platform\'s share of your sales.'))
                ->color('gray')
                ->icon('heroicon-o-receipt-percent'),

            Stat::make(__('Paid out'), Money::fromKobo(abs((int) WalletTransaction::query()
                ->where('user_id', $user->getKey())
                ->where('type', LedgerType::Withdrawal)
                ->sum('amount_kobo'))))
                ->description(__('Money already in your bank.'))
                ->color('gray')
                ->icon('heroicon-o-arrow-up-tray'),

            Stat::make(__('Orders needing you'), (string) SubOrder::query()
                ->forSeller(ProductResource::currentSeller())
                ->whereIn('status', [
                    SubOrderStatus::Pending,
                    SubOrderStatus::Accepted,
                    SubOrderStatus::Shipped,
                ])
                ->count())
                ->description(__('Accept, send, or mark them delivered.'))
                ->color('primary')
                ->icon('heroicon-o-clipboard-document-list'),
        ];
    }
}
