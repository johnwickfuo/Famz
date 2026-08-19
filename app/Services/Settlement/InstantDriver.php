<?php

namespace App\Services\Settlement;

use App\Contracts\SettlementDriver;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\SubOrderStatus;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * The seller's money is theirs the moment the payment clears.
 *
 * Suits a platform that trusts its sellers, or one operating where escrow is
 * more friction than it is worth. The commission is recorded as its own entry
 * exactly as under escrow, so the platform's books read the same either way.
 */
class InstantDriver implements SettlementDriver
{
    public const KEY = 'instant';

    public function __construct(private readonly WalletService $wallet) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function holdsFunds(): bool
    {
        return false;
    }

    public function recordSale(SubOrder $subOrder): void
    {
        DB::transaction(function () use ($subOrder): void {
            $seller = $subOrder->seller;

            $this->wallet->record(
                user: $seller->user_id,
                type: LedgerType::Sale,
                amountKobo: $subOrder->seller_payout_amount_kobo,
                state: LedgerState::Released,
                description: __('Sale :reference', ['reference' => $subOrder->reference]),
                subOrder: $subOrder,
                meta: [
                    'order_reference' => $subOrder->order->reference,
                    'subtotal_kobo' => $subOrder->subtotal_kobo,
                    'commission_percent' => (float) $subOrder->commission_percent_snapshot,
                ],
            );

            if ($subOrder->commission_amount_kobo > 0) {
                $this->wallet->record(
                    user: null,
                    type: LedgerType::Commission,
                    amountKobo: $subOrder->commission_amount_kobo,
                    state: LedgerState::Released,
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

    /**
     * Nothing was held, so there is nothing to release. The sub-order still
     * gets its settled stamp so both drivers leave the same trail.
     */
    public function release(SubOrder $subOrder, User|int|null $actor = null): bool
    {
        if ($subOrder->isSettled()) {
            return false;
        }

        $subOrder->forceFill([
            'status' => SubOrderStatus::Settled,
            'settled_at' => now(),
            'auto_release_at' => null,
        ])->save();

        $subOrder->order->syncStatusFromSubOrders();

        return true;
    }
}
