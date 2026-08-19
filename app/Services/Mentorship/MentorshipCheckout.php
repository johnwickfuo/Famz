<?php

namespace App\Services\Mentorship;

use App\Enums\EngagementStatus;
use App\Models\MentorshipEngagement;
use App\Models\MentorshipInvoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Paying for a period of mentorship.
 *
 * The Phase 3 payment layer is reused whole — same gateways, same callback,
 * same webhook, same "the order moves only on a verified webhook" rule. A
 * mentorship order simply has no sub-orders, exactly like a course order, so
 * none of the marketplace settlement runs for it.
 *
 * What is different from a course is what happens next: a course is opened and
 * the money is the platform's outright, while an engagement is activated and
 * the money is HELD until somebody confirms the work was done.
 */
class MentorshipCheckout
{
    /**
     * Start a payment for an unpaid invoice.
     */
    public function begin(User $client, MentorshipInvoice $invoice): Order
    {
        $engagement = $invoice->engagement;

        if ($engagement === null || $engagement->client_id !== $client->getKey()) {
            throw new RuntimeException(__('This is not your engagement.'));
        }

        if ($invoice->isPaid()) {
            throw new RuntimeException(__('This period has already been paid for.'));
        }

        if (in_array($engagement->status, [
            EngagementStatus::Cancelled,
            EngagementStatus::Refunded,
        ], true)) {
            throw new RuntimeException(__('This engagement is closed.'));
        }

        if ($engagement->mentor?->isBookable() !== true) {
            throw new RuntimeException(__('This mentor is not taking work at the moment.'));
        }

        return DB::transaction(function () use ($client, $invoice): Order {
            // An unpaid order already exists for this invoice — the client
            // started a payment and came back. Reuse it rather than leaving a
            // trail of abandoned references behind them.
            $existing = $invoice->order;

            if ($existing !== null && ! $existing->isPaid()) {
                return $existing;
            }

            $order = new Order([
                'user_id' => $client->getKey(),
                'currency' => $invoice->currency,
                'subtotal_kobo' => $invoice->amount_kobo,
                'delivery_total_kobo' => 0,
                'grand_total_kobo' => $invoice->amount_kobo,
                // Nothing is shipped, but the columns are not nullable and a
                // receipt reads better with the client's own details than with
                // blanks.
                'delivery_name' => $client->displayName(),
                'delivery_phone' => $client->profile?->phone ?? '—',
                'delivery_address' => __('Mentorship — nothing is shipped'),
                'delivery_state' => $client->profile?->state ?? '—',
                'delivery_lga' => $client->profile?->lga ?? '—',
            ]);

            $order->save();

            $invoice->forceFill([
                'order_id' => $order->getKey(),
                'order_reference' => $order->reference,
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * The invoices an order is paying for.
     *
     * An order pays for exactly one, but the payment processor asks in the
     * plural so a future bundled payment does not need it rewritten.
     *
     * @return Collection<int, MentorshipInvoice>
     */
    public function invoicesFor(Order $order): Collection
    {
        return MentorshipInvoice::query()
            ->where('order_id', $order->getKey())
            ->with('engagement.mentor')
            ->get();
    }

    /**
     * What the client still owes on a running engagement.
     */
    public function outstanding(MentorshipEngagement $engagement): ?MentorshipInvoice
    {
        return $engagement->nextInvoice();
    }
}
