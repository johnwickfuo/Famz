<?php

namespace App\Services\Consultations;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Paying a consultation quote.
 *
 * The Phase 3 payment layer is reused whole — same gateways, same callback,
 * same webhook, same "the order moves only on a verified webhook" rule. A
 * consultation order has no sub-orders, so none of the marketplace settlement
 * runs for it and the whole amount is the platform's: the company did the work,
 * there is nobody to split with.
 */
class ConsultationCheckout
{
    /**
     * Start a payment against a quote.
     */
    public function begin(User $client, Consultation $consultation): Order
    {
        if (! $consultation->belongsToUser($client)) {
            throw new RuntimeException(__('This is not your consultation.'));
        }

        if (! $consultation->isQuoted()) {
            throw new RuntimeException(__('There is no price on this consultation yet.'));
        }

        if ($consultation->isPaid()) {
            throw new RuntimeException(__('This consultation has already been paid for.'));
        }

        if ($consultation->status->isFinished()) {
            throw new RuntimeException(__('This consultation is closed.'));
        }

        return DB::transaction(function () use ($client, $consultation): Order {
            // They started a payment and came back. Reuse the order rather than
            // leaving a trail of abandoned references behind them.
            $existing = $consultation->order;

            if ($existing !== null && ! $existing->isPaid()) {
                return $existing;
            }

            $order = new Order([
                'user_id' => $client->getKey(),
                'currency' => $consultation->currency,
                'subtotal_kobo' => $consultation->quoted_amount_kobo,
                'delivery_total_kobo' => 0,
                'grand_total_kobo' => $consultation->quoted_amount_kobo,
                // Nothing is shipped, but the columns are not nullable and a
                // receipt reads better with the client's own details on it.
                'delivery_name' => $consultation->full_name,
                'delivery_phone' => $consultation->phone,
                'delivery_address' => __('Consultation — nothing is shipped'),
                'delivery_state' => $consultation->state ?? '—',
                'delivery_lga' => $consultation->lga ?? '—',
            ]);

            $order->save();

            $consultation->forceFill([
                'order_id' => $order->getKey(),
                'order_reference' => $order->reference,
                'status' => ConsultationStatus::AwaitingPayment,
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * The consultations an order is paying for.
     *
     * @return Collection<int, Consultation>
     */
    public function consultationsFor(Order $order): Collection
    {
        return Consultation::query()->where('order_id', $order->getKey())->get();
    }
}
