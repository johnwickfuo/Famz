<?php

namespace App\Services\Reporting;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The platform's own books.
 *
 * Everything here is a sum over the ledger, computed on demand. Nothing is
 * cached, because a figure an administrator is about to make a decision on
 * should be the figure as it is now, not as it was when a job last ran.
 */
class PlatformFinances
{
    /**
     * Commission earned between two dates.
     *
     * Held commission is included on purpose but reported separately: it is
     * revenue the platform has earned and not yet been able to keep, and an
     * administrator reading a single "earned" figure that quietly omitted it
     * would think the platform was doing worse than it is.
     */
    public function commissionBetween(?Carbon $from, ?Carbon $until): int
    {
        return (int) $this->commissionQuery($from, $until)
            ->whereIn('state', LedgerState::spendable())
            ->sum('amount_kobo');
    }

    public function commissionHeld(): int
    {
        return (int) WalletTransaction::query()
            ->platform()
            ->where('type', LedgerType::Commission)
            ->where('state', LedgerState::Held)
            ->sum('amount_kobo');
    }

    /**
     * Commission month by month, oldest first.
     *
     * @return Collection<string, int> 'Y-m' => kobo
     */
    public function commissionByMonth(int $months = 12): Collection
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $byMonth = $this->commissionQuery($start, null)
            ->whereIn('state', LedgerState::spendable())
            ->get(['amount_kobo', 'created_at'])
            ->groupBy(fn (WalletTransaction $entry): string => $entry->created_at->format('Y-m'))
            ->map(fn (Collection $entries): int => (int) $entries->sum('amount_kobo'));

        return collect(range(0, $months - 1))
            ->mapWithKeys(function (int $offset) use ($start, $byMonth): array {
                $key = $start->copy()->addMonths($offset)->format('Y-m');

                return [$key => $byMonth[$key] ?? 0];
            });
    }

    /**
     * What the gateway should have settled to the platform's bank account,
     * against what the ledger says it did.
     *
     * The two are different views of the same money: orders record what buyers
     * were charged, the ledger records what the platform then owes and owns.
     * They must agree, and where they do not, the difference is the number
     * worth looking at.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(?Carbon $from = null, ?Carbon $until = null): array
    {
        $orders = Order::query()
            ->whereIn('status', [
                OrderStatus::Paid,
                OrderStatus::PartiallyFulfilled,
                OrderStatus::Completed,
                OrderStatus::Refunded,
            ])
            ->whereNotNull('paid_at')
            ->when($from, fn ($q, $date) => $q->where('paid_at', '>=', $date))
            ->when($until, fn ($q, $date) => $q->where('paid_at', '<=', $date));

        $collected = (int) $orders->clone()->sum('grand_total_kobo');
        $orderCount = (int) $orders->clone()->count();

        $paidOrderIds = $orders->clone()->select('id');

        /*
         * What the ledger still says is owed or owned out of those payments.
         *
         * Only entries in a state that counts toward a balance are included.
         * An escrow sale cancelled by a rejection is left in `refunded`, which
         * counts toward nothing — summing it anyway would report money as
         * still credited when it had already gone back to the buyer, and the
         * books would appear out by exactly the amount of every refund.
         */
        $counting = [...LedgerState::spendable(), LedgerState::Held];

        $credited = (int) WalletTransaction::query()
            ->whereIn('type', [LedgerType::Sale, LedgerType::Commission])
            ->whereIn('state', $counting)
            ->whereHas('subOrder', fn ($query) => $query->whereIn('order_id', $paidOrderIds))
            ->sum('amount_kobo');

        $reversed = (int) WalletTransaction::query()
            ->where('type', LedgerType::Reversal)
            ->whereIn('state', $counting)
            ->whereHas('subOrder', fn ($query) => $query->whereIn('order_id', $paidOrderIds))
            ->sum('amount_kobo');

        $refunded = (int) WalletTransaction::query()
            ->where('type', LedgerType::Refund)
            ->whereHas('subOrder', fn ($query) => $query->whereIn('order_id', $paidOrderIds))
            ->sum('amount_kobo');

        $paidOut = abs((int) WalletTransaction::query()
            ->where('type', LedgerType::Withdrawal)
            ->when($from, fn ($q, $date) => $q->where('created_at', '>=', $date))
            ->when($until, fn ($q, $date) => $q->where('created_at', '<=', $date))
            ->sum('amount_kobo'));

        return [
            'orders' => $orderCount,
            'collected_kobo' => $collected,
            'credited_kobo' => $credited,
            // Credits less reversals is what the ledger still says is owed or
            // owned; it should equal what was collected less what went back.
            'net_ledger_kobo' => $credited + $reversed,
            'refunded_kobo' => $refunded,
            'expected_kobo' => $collected - $refunded,
            'difference_kobo' => ($credited + $reversed) - ($collected - $refunded),
            'paid_out_kobo' => $paidOut,
            'owed_to_sellers_kobo' => $this->owedToSellers(),
            'platform_balance_kobo' => $this->platformBalance(),
        ];
    }

    /**
     * Everything the platform is holding on other people's behalf: released
     * balances not yet withdrawn, plus escrow.
     */
    public function owedToSellers(): int
    {
        return (int) WalletTransaction::query()
            ->whereNotNull('user_id')
            ->whereIn('state', [...LedgerState::spendable(), LedgerState::Held])
            ->sum('amount_kobo');
    }

    public function platformBalance(): int
    {
        return (int) WalletTransaction::query()
            ->platform()
            ->whereIn('state', LedgerState::spendable())
            ->sum('amount_kobo');
    }

    /**
     * Payouts waiting on somebody.
     *
     * @return array{count: int, amount_kobo: int}
     */
    public function pendingWithdrawals(): array
    {
        $query = Withdrawal::query()->whereIn('status', [
            WithdrawalStatus::Requested,
            WithdrawalStatus::Approved,
            WithdrawalStatus::Processing,
        ]);

        return [
            'count' => (int) $query->clone()->count(),
            'amount_kobo' => (int) $query->clone()->sum('amount_kobo'),
        ];
    }

    private function commissionQuery(?Carbon $from, ?Carbon $until)
    {
        return WalletTransaction::query()
            ->platform()
            ->where('type', LedgerType::Commission)
            ->when($from, fn ($q, $date) => $q->where('created_at', '>=', $date))
            ->when($until, fn ($q, $date) => $q->where('created_at', '<=', $date));
    }
}
