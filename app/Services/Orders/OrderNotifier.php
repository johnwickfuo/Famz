<?php

namespace App\Services\Orders;

use App\Models\Enrolment;
use App\Models\Order;
use App\Models\SubOrder;
use App\Notifications\CoursePurchased;
use App\Notifications\OrderPaid;
use App\Notifications\OrderShipped;
use App\Notifications\SellerOrderReceived;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who hears about an order, and when.
 *
 * Two rules hold everywhere in here.
 *
 * **Nothing runs inside the payment transaction.** A notification is not worth
 * rolling back a payment for, and a mail provider timing out inside a
 * transaction holds a row lock on an order while it does.
 *
 * **Nothing here can throw.** The caller has already taken somebody's money and
 * written the ledger; an exception at this point would be reported as a failed
 * webhook, the gateway would redeliver it, and the redelivery would find the
 * order already paid and do nothing — leaving a real payment looking like a
 * failure forever. So every send is caught and logged instead.
 */
class OrderNotifier
{
    /**
     * Everybody with a stake in a payment that just succeeded.
     *
     * The buyer, because they have parted with money and want to see it landed.
     * Each seller, because an order nobody knows about does not get packed —
     * which is the actual reason the buyer eventually gets what they paid for.
     */
    public function paid(Order $order): void
    {
        $order->loadMissing('user', 'subOrders.seller.user', 'subOrders.items');

        $this->quietly(fn () => $order->user?->notify(new OrderPaid($order)));

        foreach ($order->subOrders as $subOrder) {
            $this->quietly(fn () => $subOrder->seller?->user?->notify(new SellerOrderReceived($subOrder)));
        }
    }

    /**
     * A course order opens a course rather than producing a sub-order, so the
     * loop above does nothing for it. Without this, somebody pays for training
     * and hears nothing at all.
     */
    public function coursesOpened(Order $order): void
    {
        $enrolments = Enrolment::query()
            ->where('order_id', $order->getKey())
            ->with('course', 'user')
            ->get();

        foreach ($enrolments as $enrolment) {
            $this->quietly(fn () => $enrolment->user?->notify(new CoursePurchased($enrolment)));
        }
    }

    public function shipped(SubOrder $subOrder): void
    {
        $subOrder->loadMissing('order.user', 'seller');

        $this->quietly(fn () => $subOrder->order?->user?->notify(new OrderShipped($subOrder)));
    }

    private function quietly(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $exception) {
            // Logged rather than swallowed: somebody not being told is a real
            // problem, just not one worth failing a payment over.
            Log::error('An order notification could not be sent.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
