<?php

namespace App\Services\Quotations;

use App\Models\Order;
use App\Models\QuotationRequest;
use App\Models\QuotationStudyFee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Paying the study fee.
 *
 * The Phase 3 payment layer is reused whole — same gateways, same callback,
 * same webhook, same "the request moves only on a verified webhook" rule. A
 * study-fee order has no sub-orders, so none of the marketplace settlement runs
 * for it and the whole amount is the platform's: the company is being paid for
 * its own work and there is nobody to split with.
 */
class StudyFeeCheckout
{
    public function __construct(private readonly StudyFeeService $fees) {}

    /**
     * Start a payment against the study fee for a request.
     */
    public function begin(User $client, QuotationRequest $request): Order
    {
        if (! $request->belongsToUser($client)) {
            throw new RuntimeException(__('This is not your request.'));
        }

        if ($request->isClosed()) {
            throw new RuntimeException(__('This request is closed.'));
        }

        $fee = $this->fees->raiseFor($request);

        if ($fee->isPaid()) {
            throw new RuntimeException(__('This study fee has already been paid.'));
        }

        return DB::transaction(function () use ($client, $request, $fee): Order {
            // They started a payment and came back. Reuse the order rather than
            // leaving a trail of abandoned references behind them.
            $existing = $fee->order;

            if ($existing !== null && ! $existing->isPaid()) {
                return $existing;
            }

            $order = new Order([
                'user_id' => $client->getKey(),
                'currency' => $fee->currency,
                'subtotal_kobo' => $fee->amount_kobo,
                'delivery_total_kobo' => 0,
                'grand_total_kobo' => $fee->amount_kobo,
                // Nothing is shipped, but the columns are not nullable and a
                // receipt reads better with the client's own details on it.
                'delivery_name' => $client->name,
                'delivery_phone' => $client->profile?->phone ?? '—',
                'delivery_address' => __('Farm setup study — nothing is shipped'),
                'delivery_state' => $request->state ?? '—',
                'delivery_lga' => $request->lga ?? '—',
            ]);

            $order->save();

            $fee->forceFill([
                'order_id' => $order->getKey(),
                'payment_reference' => $order->reference,
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * The study fees an order is paying for.
     *
     * @return Collection<int, QuotationStudyFee>
     */
    public function feesFor(Order $order): Collection
    {
        return QuotationStudyFee::query()->where('order_id', $order->getKey())->get();
    }
}
