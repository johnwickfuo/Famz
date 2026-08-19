<?php

namespace App\Services\Settlement;

use App\Contracts\SettlementDriver;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\SubOrderStatus;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hold the money until the buyer has the goods.
 *
 * The commission is held alongside the payout rather than taken immediately.
 * If the sale falls through, both cancel together and the ledger needs no
 * unpicking — and a platform that has already spent its cut of an order that
 * was never delivered is a platform with a hole in it.
 */
class EscrowDriver implements SettlementDriver
{
    public const KEY = 'escrow';

    /**
     * Days after the seller marks delivery before the money releases on its
     * own, if nobody has disputed it.
     */
    public const DEFAULT_AUTO_RELEASE_DAYS = 7;

    public function __construct(private readonly WalletService $wallet) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function holdsFunds(): bool
    {
        return true;
    }

    public function recordSale(SubOrder $subOrder): void
    {
        DB::transaction(function () use ($subOrder): void {
            $seller = $subOrder->seller;

            $this->wallet->record(
                user: $seller->user_id,
                type: LedgerType::Sale,
                amountKobo: $subOrder->sellerCreditKobo(),
                state: LedgerState::Held,
                description: __('Sale :reference', ['reference' => $subOrder->reference]),
                subOrder: $subOrder,
                meta: [
                    'order_reference' => $subOrder->order->reference,
                    'subtotal_kobo' => $subOrder->subtotal_kobo,
                    'delivery_fee_kobo' => $subOrder->delivery_fee_kobo,
                    'commission_percent' => (float) $subOrder->commission_percent_snapshot,
                ],
            );

            if ($subOrder->commission_amount_kobo > 0) {
                $this->wallet->record(
                    user: null,
                    type: LedgerType::Commission,
                    amountKobo: $subOrder->commission_amount_kobo,
                    state: LedgerState::Held,
                    description: __('Commission on :reference', ['reference' => $subOrder->reference]),
                    subOrder: $subOrder,
                    meta: [
                        'seller_id' => $subOrder->seller_id,
                        'commission_percent' => (float) $subOrder->commission_percent_snapshot,
                    ],
                );
            }
        });
    }

    public function release(SubOrder $subOrder, User|int|null $actor = null): bool
    {
        // A dispute stops the clock: nothing moves until somebody resolves it.
        if ($subOrder->isDisputed()) {
            return false;
        }

        return DB::transaction(function () use ($subOrder): bool {
            $moved = $this->wallet->releaseHeld($subOrder);

            if ($moved === 0) {
                // Already released, or never held. Either way there is nothing
                // to do, and saying so lets the caller stay quiet about it.
                return false;
            }

            $subOrder->forceFill([
                'status' => SubOrderStatus::Settled,
                'settled_at' => now(),
                'auto_release_at' => null,
            ])->save();

            $subOrder->order->syncStatusFromSubOrders();

            return true;
        });
    }

    /**
     * When this sub-order will release on its own.
     */
    public function autoReleaseAt(SubOrder $subOrder): ?Carbon
    {
        // The clock starts at delivery, not at payment: a buyer waiting three
        // weeks for day-old chicks must not have their window expire before
        // the birds arrive.
        return $subOrder->delivered_at === null ? null : self::windowFrom();
    }

    /**
     * The end of the escrow window, counted from now.
     */
    public static function windowFrom(): Carbon
    {
        $days = (int) settings('escrow_auto_release_days', self::DEFAULT_AUTO_RELEASE_DAYS);

        return now()->addDays(max(1, $days));
    }
}
