<?php

namespace App\Services\Payments;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\SubOrderStatus;
use App\Models\Consultation;
use App\Models\Enrolment;
use App\Models\MentorshipInvoice;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Services\Consultations\ConsultationService;
use App\Services\Mentorship\EngagementService;
use App\Services\Settlement\SettlementManager;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marking an order paid, exactly once.
 *
 * Everything about this class exists to survive a gateway that delivers the
 * same webhook three times, out of order, days apart. It re-verifies with the
 * gateway rather than trusting the webhook body, checks the amount and currency
 * match what was actually ordered, and takes a row lock so two concurrent
 * deliveries cannot both decide they were first.
 */
class PaymentProcessor
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly SettlementManager $settlement,
        private readonly WalletService $wallet,
        private readonly EngagementService $engagements,
        private readonly ConsultationService $consultations,
    ) {}

    /**
     * Mark an order paid from a gateway reference.
     *
     * Returns true only when this call is the one that changed the order, so a
     * caller can tell a first delivery from a redelivery.
     */
    public function markPaidFromReference(string $gatewayKey, string $reference): bool
    {
        $order = Order::query()->where('reference', $reference)->first();

        if ($order === null) {
            Log::warning('Payment webhook referenced an unknown order.', [
                'gateway' => $gatewayKey,
                'reference' => $reference,
            ]);

            return false;
        }

        // Cheap early exit before doing any network work.
        if ($order->isPaid()) {
            return false;
        }

        $gateway = $this->gateways->gateway($gatewayKey);

        // The webhook body is a notification, not evidence. What the gateway
        // says when asked directly is the only thing worth acting on.
        $verification = $gateway->verify($reference);

        if (! $verification->matches($order->grand_total_kobo, $order->currency)) {
            Log::warning('Payment verification did not match the order.', [
                'gateway' => $gatewayKey,
                'reference' => $reference,
                'expected_kobo' => $order->grand_total_kobo,
                'expected_currency' => $order->currency,
                'reported_kobo' => $verification->amountKobo,
                'reported_currency' => $verification->currency,
                'successful' => $verification->successful,
            ]);

            return false;
        }

        return $this->markPaid($order, $gatewayKey, $verification->gatewayReference ?? $reference);
    }

    /**
     * The state change itself, under a lock.
     */
    public function markPaid(Order $order, string $gatewayKey, string $gatewayReference): bool
    {
        return DB::transaction(function () use ($order, $gatewayKey, $gatewayReference): bool {
            // Re-read inside the transaction with a row lock: two webhook
            // deliveries arriving together would otherwise both see an unpaid
            // order and both write the ledger.
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isPaid()) {
                return false;
            }

            $locked->forceFill([
                'status' => OrderStatus::Paid,
                'payment_gateway' => $gatewayKey,
                'gateway_reference' => $gatewayReference,
                'paid_at' => now(),
            ])->save();

            $driver = $this->settlement->driver();

            $locked->loadMissing('subOrders.seller', 'subOrders.items');

            foreach ($locked->subOrders as $subOrder) {
                // Every seller's part starts waiting on that seller.
                $subOrder->forceFill(['status' => SubOrderStatus::Pending])->save();

                $driver->recordSale($subOrder);

                $this->decrementStock($subOrder);
            }

            // A course order has no sub-orders at all, so the loop above does
            // nothing for it. Opening the course is what "paid" means there.
            $this->openCourses($locked);

            // Nor does a mentorship order. Activating the engagement — and
            // holding the money — is what "paid" means there.
            $this->openMentorship($locked);

            // Nor a consultation, where "paid" means the company can get on
            // with the work it already quoted for.
            $this->openConsultations($locked);

            return true;
        });
    }

    /**
     * Open any course this order paid for.
     *
     * Course money is the platform's in full: no seller, no commission split,
     * no escrow. The single ledger entry says so, and it is released rather
     * than held because there is nobody to hold it from.
     */
    private function openCourses(Order $order): void
    {
        $enrolments = Enrolment::query()
            ->where('order_id', $order->getKey())
            ->with('course')
            ->get();

        foreach ($enrolments as $enrolment) {
            if ($enrolment->isActive()) {
                continue;
            }

            $enrolment->forceFill([
                'enrolled_at' => now(),
                'price_paid_kobo' => $enrolment->price_paid_kobo ?: $order->grand_total_kobo,
            ])->save();

            $this->wallet->record(
                user: null,
                type: LedgerType::CourseSale,
                amountKobo: $enrolment->price_paid_kobo,
                state: LedgerState::Released,
                description: __('Course sale: :title', ['title' => $enrolment->course?->title ?? $enrolment->reference]),
                meta: [
                    'enrolment_id' => $enrolment->getKey(),
                    'course_id' => $enrolment->course_id,
                    'order_reference' => $order->reference,
                ],
            );
        }
    }

    /**
     * Start any mentorship this order paid for.
     *
     * The opposite of a course: the money is HELD, not released. Nobody has
     * earned anything until the work is confirmed, and the platform has not
     * earned its commission either.
     */
    private function openMentorship(Order $order): void
    {
        $invoices = MentorshipInvoice::query()
            ->where('order_id', $order->getKey())
            ->with('engagement.mentor')
            ->get();

        foreach ($invoices as $invoice) {
            // Idempotent inside: a redelivered webhook writes one set of
            // entries and activates nothing twice.
            $this->engagements->markInvoicePaid($invoice, $order);
        }
    }

    /**
     * Settle any consultation this order paid for.
     *
     * Like a course and unlike a mentorship: the company did the work itself,
     * so the whole amount is the platform's and it is released rather than
     * held. There is nobody to hold it from.
     */
    private function openConsultations(Order $order): void
    {
        $consultations = Consultation::query()->where('order_id', $order->getKey())->get();

        foreach ($consultations as $consultation) {
            // Idempotent inside: a redelivered webhook settles this once.
            if (! $this->consultations->markPaid($consultation, $order->reference)) {
                continue;
            }

            $this->wallet->record(
                user: null,
                type: LedgerType::ConsultationFee,
                amountKobo: $consultation->quoted_amount_kobo ?? $order->grand_total_kobo,
                state: LedgerState::Released,
                description: __('Consultation :reference', ['reference' => $consultation->reference]),
                meta: [
                    'consultation_id' => $consultation->getKey(),
                    'consultation_reference' => $consultation->reference,
                    'order_reference' => $order->reference,
                    'tier' => $consultation->tier->value,
                ],
            );
        }
    }

    /**
     * Take the sold quantity out of stock.
     *
     * Done at payment rather than at add-to-cart, because a cart is an
     * intention and holding stock against every intention starves the ones who
     * actually pay.
     */
    private function decrementStock(SubOrder $subOrder): void
    {
        foreach ($subOrder->items as $item) {
            if ($item->product_variant_id !== null) {
                ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->where('stock_quantity', '>=', $item->quantity)
                    ->decrement('stock_quantity', $item->quantity);
            }

            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $product->stock_quantity = max(0, $product->stock_quantity - $item->quantity);

            // Saving through the model lets it move itself to out-of-stock
            // when the last one goes.
            $product->save();
        }
    }
}
