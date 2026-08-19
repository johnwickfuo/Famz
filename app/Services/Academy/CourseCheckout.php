<?php

namespace App\Services\Academy;

use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Buying a course.
 *
 * The Phase 3 payment layer is reused whole — same gateway, same callback, same
 * webhook, same "the order moves only on a verified webhook" rule. What is not
 * reused is the split: a course order has **no sub-orders**, so there is no
 * seller, no commission and no escrow, and the whole amount is the platform's
 * from the moment it clears.
 *
 * That falls out of the schema rather than being special-cased in the payment
 * code: `PaymentProcessor` loops over sub-orders and a course order has none.
 */
class CourseCheckout
{
    public function __construct(private readonly EnrolmentService $enrolments) {}

    /**
     * Create the order and the pending enrolment, together.
     *
     * The enrolment is written now, without `enrolled_at`, so the consent is
     * captured at the moment it was given rather than reconstructed later from
     * a webhook. It opens nothing until the money clears.
     */
    public function begin(User $buyer, Course $course, Request $request): Order
    {
        if (! $course->isPurchasable()) {
            throw new RuntimeException(__('This course is not on sale.'));
        }

        if ($course->is_free || $course->price_kobo < 1) {
            throw new RuntimeException(__('This course is free — there is nothing to pay.'));
        }

        if ($this->enrolments->isEnrolled($buyer, $course)) {
            throw new RuntimeException(__('You already have this course.'));
        }

        return DB::transaction(function () use ($buyer, $course, $request): Order {
            $order = new Order([
                'user_id' => $buyer->getKey(),
                'currency' => $course->currency,
                'subtotal_kobo' => $course->price_kobo,
                'delivery_total_kobo' => 0,
                'grand_total_kobo' => $course->price_kobo,
                // Nothing is delivered anywhere, but the columns are not
                // nullable and a receipt reads better with the buyer's own
                // details on it than with blanks.
                'delivery_name' => $buyer->displayName(),
                'delivery_phone' => $buyer->profile?->phone ?? '—',
                'delivery_address' => __('Online course — nothing is shipped'),
                'delivery_state' => $buyer->profile?->state ?? '—',
                'delivery_lga' => $buyer->profile?->lga ?? '—',
            ]);

            $order->save();

            $enrolment = $this->enrolments->reserve($buyer, $course, $request);

            $enrolment->forceFill([
                'order_id' => $order->getKey(),
                'order_reference' => $order->reference,
                'price_paid_kobo' => $course->price_kobo,
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * A free course needs no gateway at all.
     */
    public function enrolFree(User $buyer, Course $course, Request $request): Enrolment
    {
        if (! $course->isPurchasable()) {
            throw new RuntimeException(__('This course is not on sale.'));
        }

        if (! $course->is_free && $course->price_kobo > 0) {
            throw new RuntimeException(__('This course has to be paid for.'));
        }

        return $this->enrolments->enrol($buyer, $course, null, 0, $request);
    }
}
