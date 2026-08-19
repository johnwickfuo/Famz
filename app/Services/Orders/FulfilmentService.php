<?php

namespace App\Services\Orders;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\SubOrderStatus;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Settlement\EscrowDriver;
use App\Services\Settlement\SettlementManager;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moving a seller's part of an order through to done — or to refunded.
 *
 * Each transition is a method rather than a status setter, because each one has
 * consequences beyond the column: accepting starts the clock, delivery starts
 * the escrow release window, rejection puts money back.
 */
class FulfilmentService
{
    public function __construct(
        private readonly SettlementManager $settlement,
        private readonly WalletService $wallet,
    ) {}

    public function accept(SubOrder $subOrder, ?User $actor = null): SubOrder
    {
        $this->assertStatus($subOrder, [SubOrderStatus::Pending], __('accept'));

        $subOrder->forceFill([
            'status' => SubOrderStatus::Accepted,
            'accepted_at' => now(),
        ])->save();

        $subOrder->order->syncStatusFromSubOrders();

        return $subOrder;
    }

    /**
     * The seller cannot fulfil. The buyer gets their money back, and the ledger
     * says why.
     */
    public function reject(SubOrder $subOrder, string $reason, ?User $actor = null): SubOrder
    {
        $this->assertStatus($subOrder, [SubOrderStatus::Pending, SubOrderStatus::Accepted], __('reject'));

        if (trim($reason) === '') {
            throw new RuntimeException(__('Tell the buyer why, so they know what to do next.'));
        }

        return DB::transaction(function () use ($subOrder, $reason, $actor): SubOrder {
            $subOrder->forceFill([
                'status' => SubOrderStatus::Rejected,
                'rejection_reason' => $reason,
                'rejected_at' => now(),
                'auto_release_at' => null,
            ])->save();

            $this->refundLedger($subOrder, $reason, $actor);
            $this->restoreStock($subOrder);

            $subOrder->order->syncStatusFromSubOrders();

            return $subOrder;
        });
    }

    public function markShipped(SubOrder $subOrder, ?User $actor = null): SubOrder
    {
        $this->assertStatus($subOrder, [SubOrderStatus::Accepted], __('mark as shipped'));

        $subOrder->forceFill([
            'status' => SubOrderStatus::Shipped,
            'shipped_at' => now(),
        ])->save();

        $subOrder->order->syncStatusFromSubOrders();

        return $subOrder;
    }

    /**
     * The seller says it has arrived. Under escrow this starts the clock: if
     * the buyer says nothing and raises no dispute, the money releases on its
     * own after the configured window.
     */
    public function markDelivered(SubOrder $subOrder, ?User $actor = null): SubOrder
    {
        $this->assertStatus(
            $subOrder,
            [SubOrderStatus::Accepted, SubOrderStatus::Shipped],
            __('mark as delivered'),
        );

        $holdsFunds = $this->settlement->driver()->holdsFunds();

        $subOrder->forceFill([
            'status' => SubOrderStatus::Delivered,
            'delivered_at' => now(),
            'auto_release_at' => $holdsFunds ? EscrowDriver::windowFrom() : null,
        ])->save();

        $subOrder->order->syncStatusFromSubOrders();

        return $subOrder;
    }

    /**
     * The buyer confirms. Money moves immediately — there is nothing left to
     * wait for.
     */
    public function markReceived(SubOrder $subOrder, ?User $actor = null): SubOrder
    {
        $this->assertStatus(
            $subOrder,
            [SubOrderStatus::Shipped, SubOrderStatus::Delivered, SubOrderStatus::Accepted],
            __('confirm as received'),
        );

        return DB::transaction(function () use ($subOrder, $actor): SubOrder {
            $subOrder->forceFill([
                'received_at' => now(),
                'delivered_at' => $subOrder->delivered_at ?? now(),
                'status' => SubOrderStatus::Delivered,
            ])->save();

            $this->settlement->driver()->release($subOrder->refresh(), $actor);

            return $subOrder->refresh();
        });
    }

    /**
     * The buyer says something is wrong. The clock stops until somebody
     * resolves it.
     */
    public function dispute(SubOrder $subOrder, string $reason = '', ?User $actor = null): SubOrder
    {
        if ($subOrder->isSettled()) {
            throw new RuntimeException(__('This order has already been settled.'));
        }

        $subOrder->forceFill([
            'status' => SubOrderStatus::Disputed,
            'disputed_at' => now(),
            // Cleared so the automatic release cannot fire while the dispute is
            // open. This is the whole point of the flag.
            'auto_release_at' => null,
        ])->save();

        $subOrder->order->syncStatusFromSubOrders();

        return $subOrder;
    }

    /**
     * Release everything whose escrow window has passed without a dispute.
     *
     * Returns how many were released, for the scheduled command's output.
     */
    public function releaseDueEscrow(): int
    {
        $driver = $this->settlement->driver();

        if (! $driver->holdsFunds()) {
            return 0;
        }

        $released = 0;

        SubOrder::query()
            ->awaitingAutoRelease()
            ->with(['seller', 'order'])
            ->chunkById(100, function ($subOrders) use ($driver, &$released): void {
                foreach ($subOrders as $subOrder) {
                    if ($driver->release($subOrder)) {
                        $released++;
                    }
                }
            });

        return $released;
    }

    /**
     * Put the money back.
     *
     * Held entries are simply cancelled — they never counted toward a balance.
     * Released entries get an opposite reversal entry, because a ledger that
     * can be rewritten is not evidence of anything. Either way one refund entry
     * records what the buyer is owed, for audit; it is deliberately in a state
     * that counts toward no balance, because the money goes back to their card
     * rather than into a wallet.
     */
    private function refundLedger(SubOrder $subOrder, string $reason, ?User $actor): void
    {
        $this->wallet->reverseForSubOrder($subOrder, $reason, $actor);

        $this->wallet->record(
            user: $subOrder->order->user_id,
            type: LedgerType::Refund,
            amountKobo: $subOrder->grandTotalKobo(),
            state: LedgerState::Refunded,
            description: __('Refund for :reference', ['reference' => $subOrder->reference]),
            subOrder: $subOrder,
            meta: [
                'reason' => $reason,
                'subtotal_kobo' => $subOrder->subtotal_kobo,
                'delivery_fee_kobo' => $subOrder->delivery_fee_kobo,
            ],
            createdBy: $actor,
        );
    }

    /**
     * A rejected order's goods were never sold, so they go back on the shelf.
     */
    private function restoreStock(SubOrder $subOrder): void
    {
        foreach ($subOrder->items as $item) {
            if ($item->product_variant_id !== null) {
                ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->increment('stock_quantity', $item->quantity);
            }

            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $product->stock_quantity += $item->quantity;
            $product->save();
        }
    }

    /**
     * @param  array<int, SubOrderStatus>  $allowed
     */
    private function assertStatus(SubOrder $subOrder, array $allowed, string $action): void
    {
        if (in_array($subOrder->status, $allowed, true)) {
            return;
        }

        throw new RuntimeException(__('You cannot :action an order that is :status.', [
            'action' => $action,
            'status' => mb_strtolower($subOrder->status->label()),
        ]));
    }
}
