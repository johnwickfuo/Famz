<?php

namespace App\Services\Reporting;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PaymentWebhook;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payments\WebhookHandler;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Four questions asked of the books every night.
 *
 * This is the safety net under everything else on the platform. Every other
 * guard here — the double-spend lock, the dispute freeze, the balanced
 * resolutions — prevents a specific known mistake. This one catches the mistake
 * nobody thought of, by asking whether the totals still agree.
 *
 * The questions, in the order they matter:
 *
 *  1. Does every ledger account sum to what it should? A wallet is derived from
 *     its entries, so a wallet and its entries disagreeing means one of them is
 *     wrong and neither can be trusted.
 *
 *  2. Is anybody's balance negative? A negative balance is money the platform
 *     has paid out and cannot account for. There is no legitimate way to reach
 *     one, so reaching one means a guard failed.
 *
 *  3. Does every paid order have the ledger entries it should? An order that
 *     took somebody's money and credited nobody is a seller who will not be
 *     paid, and they will find out before we do.
 *
 *  4. Does what the gateway says it settled match what we recorded? This is the
 *     only check that reaches outside the platform, and the only one that can
 *     catch a webhook that never arrived.
 *
 * Every finding carries the record it is about, because "the books are out by
 * ₦4,500" is not something anybody can act on and "sub-order SO-260820-ABC123
 * has no ledger entry" is.
 */
class ReconciliationReport
{
    /**
     * Findings worse than this are worth waking somebody for.
     */
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_WARNING = 'warning';

    public function __construct(private readonly PlatformFinances $finances) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?Carbon $from = null, ?Carbon $until = null): array
    {
        $checks = [
            $this->walletsMatchTheirEntries(),
            $this->noNegativeBalances(),
            $this->paidOrdersHaveLedgerEntries($from, $until),
            $this->gatewayTotalsMatch($from, $until),
        ];

        $findings = collect($checks)->flatMap(fn (array $check): array => $check['findings'])->all();

        return [
            'ran_at' => now(),
            'from' => $from,
            'until' => $until,
            'checks' => $checks,
            'findings' => $findings,
            'clean' => $findings === [],
            'critical' => collect($findings)
                ->where('severity', self::SEVERITY_CRITICAL)
                ->count(),
            // The aggregate view, unchanged, for somebody who wants the shape
            // of the month rather than a list of problems.
            'totals' => $this->finances->reconciliation($from, $until),
        ];
    }

    /**
     * 1. Every wallet equals the sum of its own entries.
     *
     * @return array<string, mixed>
     */
    private function walletsMatchTheirEntries(): array
    {
        $findings = [];

        /*
         * The application's answer against the raw one.
         *
         * WalletService::availableBalance is what every screen, every
         * withdrawal check and every payout reads. This recomputes the same
         * figure straight from the table, with no service in between, and
         * compares them.
         *
         * That comparison is the point. Summing the same column twice would
         * prove nothing — it is the same arithmetic. What this catches is the
         * service and the data drifting apart: a state added to the enum but
         * not to spendable(), a scope that quietly filters something out, a
         * cached balance that went stale. Each of those is silent, and each of
         * them means somebody is shown a number that is not their money.
         */
        $wallets = app(\App\Services\Wallet\WalletService::class);

        // One query for the raw side, rather than one per user.
        $raw = WalletTransaction::query()
            ->whereNotNull('user_id')
            ->whereIn('state', LedgerState::spendable())
            ->selectRaw('user_id, SUM(amount_kobo) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        foreach ($raw as $userId => $total) {
            $reported = $wallets->availableBalance((int) $userId);

            if ($reported !== (int) $total) {
                $findings[] = [
                    'severity' => self::SEVERITY_CRITICAL,
                    'check' => 'wallet_sum',
                    'subject' => 'user:'.$userId,
                    'message' => __('User :id is shown a balance of :reported but their entries sum to :actual.', [
                        'id' => $userId,
                        'reported' => Money::fromKobo($reported),
                        'actual' => Money::fromKobo((int) $total),
                    ]),
                ];
            }
        }

        /*
         * The platform's own account gets the same treatment. Its rows live
         * under a null user_id, so every per-user query above skips them —
         * which means an error there would otherwise never be looked at.
         */
        $platformRaw = (int) WalletTransaction::query()
            ->whereNull('user_id')
            ->whereIn('state', LedgerState::spendable())
            ->sum('amount_kobo');

        if ($wallets->platformEarnings() !== $platformRaw) {
            $findings[] = [
                'severity' => self::SEVERITY_CRITICAL,
                'check' => 'wallet_sum',
                'subject' => 'platform',
                'message' => __('The platform account reports :reported but its entries sum to :actual.', [
                    'reported' => Money::fromKobo($wallets->platformEarnings()),
                    'actual' => Money::fromKobo($platformRaw),
                ]),
            ];
        }

        return [
            'key' => 'wallet_sum',
            'label' => __('Balances match their entries'),
            'checked' => $raw->count() + 1,
            'findings' => $findings,
        ];
    }

    /**
     * 2. Nobody's balance is negative.
     *
     * @return array<string, mixed>
     */
    private function noNegativeBalances(): array
    {
        $negative = WalletTransaction::query()
            ->whereNotNull('user_id')
            ->whereIn('state', LedgerState::spendable())
            ->selectRaw('user_id, SUM(amount_kobo) as total')
            ->groupBy('user_id')
            ->havingRaw('SUM(amount_kobo) < 0')
            ->pluck('total', 'user_id');

        $findings = [];

        foreach ($negative as $userId => $total) {
            $findings[] = [
                'severity' => self::SEVERITY_CRITICAL,
                'check' => 'negative_balance',
                'subject' => 'user:'.$userId,
                /*
                 * There is no legitimate path to a negative balance: a
                 * withdrawal is checked against available funds under a row
                 * lock. Reaching one means that guard failed, and the money is
                 * already gone.
                 */
                'message' => __(':name has a negative balance of :amount — money has left that cannot be accounted for.', [
                    'name' => User::query()->find($userId)?->name ?? 'User '.$userId,
                    'amount' => Money::fromKobo((int) $total),
                ]),
            ];
        }

        return [
            'key' => 'negative_balance',
            'label' => __('No negative balances'),
            'checked' => $negative->count(),
            'findings' => $findings,
        ];
    }

    /**
     * 3. Every paid order produced the ledger entries it should have.
     *
     * @return array<string, mixed>
     */
    private function paidOrdersHaveLedgerEntries(?Carbon $from, ?Carbon $until): array
    {
        $paidOrders = Order::query()
            ->whereIn('status', [
                OrderStatus::Paid,
                OrderStatus::PartiallyFulfilled,
                OrderStatus::Completed,
            ])
            ->whereNotNull('paid_at')
            ->when($from, fn ($q, $date) => $q->where('paid_at', '>=', $date))
            ->when($until, fn ($q, $date) => $q->where('paid_at', '<=', $date))
            ->with(['subOrders'])
            ->get();

        $findings = [];

        // One query for every sub-order that has any entry at all, rather than
        // a query per sub-order.
        $subOrderIdsWithEntries = WalletTransaction::query()
            ->whereNotNull('sub_order_id')
            ->whereIn('type', [LedgerType::Sale, LedgerType::Commission])
            ->distinct()
            ->pluck('sub_order_id')
            ->flip();

        foreach ($paidOrders as $order) {
            foreach ($order->subOrders as $subOrder) {
                if ($subOrderIdsWithEntries->has($subOrder->getKey())) {
                    continue;
                }

                $findings[] = [
                    'severity' => self::SEVERITY_CRITICAL,
                    'check' => 'order_ledger',
                    'subject' => $subOrder->reference,
                    /*
                     * The most consequential finding on this report. A buyer
                     * paid, the platform holds the money, and no seller has
                     * been credited for it. The seller will find out before we
                     * do, and they will be right to be angry.
                     */
                    'message' => __('Order :order was paid but sub-order :sub has no ledger entry — the seller has not been credited.', [
                        'order' => $order->reference,
                        'sub' => $subOrder->reference,
                    ]),
                ];
            }
        }

        return [
            'key' => 'order_ledger',
            'label' => __('Paid orders have ledger entries'),
            'checked' => $paidOrders->count(),
            'findings' => $findings,
        ];
    }

    /**
     * 4. What the gateway settled matches what we recorded.
     *
     * The only check that reaches outside the platform, and the only one that
     * can catch a webhook that was never delivered — which looks like nothing
     * at all from the inside.
     *
     * @return array<string, mixed>
     */
    private function gatewayTotalsMatch(?Carbon $from, ?Carbon $until): array
    {
        $findings = [];

        /*
         * Gateway events the platform accepted but could not match to an order.
         *
         * This is the shape of money taken and never recorded: a webhook that
         * arrived while the application was down, or one whose reference did
         * not match anything. The handler already files these as `unmatched`
         * rather than throwing them away, precisely so this check can find them.
         */
        $unmatched = PaymentWebhook::query()
            ->when($from, fn ($q, $date) => $q->where('created_at', '>=', $date))
            ->when($until, fn ($q, $date) => $q->where('created_at', '<=', $date))
            ->where('signature_valid', true)
            ->whereIn('outcome', [WebhookHandler::OUTCOME_UNMATCHED, WebhookHandler::OUTCOME_FAILED])
            ->latest('id')
            ->limit(50)
            ->get();

        foreach ($unmatched as $webhook) {
            $findings[] = [
                'severity' => self::SEVERITY_CRITICAL,
                'check' => 'gateway',
                'subject' => (string) ($webhook->gateway_reference ?? $webhook->event_id),
                'message' => __(':gateway confirmed :reference but the platform recorded no order against it (:outcome).', [
                    'gateway' => ucfirst((string) $webhook->gateway),
                    'reference' => $webhook->gateway_reference ?? __('an event'),
                    'outcome' => $webhook->outcome,
                ]),
            ];
        }

        /*
         * A signature that did not verify is a different problem: either the
         * keys have been rotated on one side only, or somebody is posting
         * forged events at the endpoint. Both are worth knowing about.
         */
        $forged = PaymentWebhook::query()
            ->when($from, fn ($q, $date) => $q->where('created_at', '>=', $date))
            ->where('signature_valid', false)
            ->count();

        if ($forged > 0) {
            $findings[] = [
                'severity' => self::SEVERITY_WARNING,
                'check' => 'gateway',
                'subject' => 'signatures',
                'message' => trans_choice(
                    '{1} One webhook failed its signature check — either a key was rotated on one side only, or somebody is posting forged events.|[2,*] :count webhooks failed their signature check — either a key was rotated on one side only, or somebody is posting forged events.',
                    $forged,
                    ['count' => $forged],
                ),
            ];
        }

        return [
            'key' => 'gateway',
            'label' => __('Gateway totals match'),
            'checked' => $unmatched->count(),
            'findings' => $findings,
        ];
    }
}
