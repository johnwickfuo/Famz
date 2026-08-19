<?php

namespace App\Services\Offers;

use App\Enums\BuyerRequestStatus;
use App\Enums\OfferStatus;
use App\Models\BuyerRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Wanted ads, from submission to closure.
 */
class BuyerRequestService
{
    public function __construct(private readonly OfferNotifier $notifier) {}

    /**
     * How long an approved request stays on the board.
     */
    public function lifetimeDays(): int
    {
        return max(1, (int) settings('buyer_request_expiry_days', 14));
    }

    /**
     * How many days before closing the buyer is warned.
     */
    public function warningDays(): int
    {
        return max(1, (int) settings('buyer_request_warning_days', 3));
    }

    /**
     * An administrator publishes it.
     *
     * The clock starts here, not at submission: a buyer whose ad sat in a
     * queue for four days should not lose four days of it.
     */
    public function approve(BuyerRequest $request, User $admin): BuyerRequest
    {
        if ($request->status !== BuyerRequestStatus::PendingApproval) {
            throw new RuntimeException(__('This request has already been dealt with.'));
        }

        $request->forceFill([
            'status' => BuyerRequestStatus::Open,
            'approved_by' => $admin->getKey(),
            'approved_at' => now(),
            'expires_at' => now()->addDays($this->lifetimeDays()),
            'rejection_reason' => null,
        ])->save();

        $this->notifier->requestReviewed($request, approved: true);

        return $request;
    }

    public function reject(BuyerRequest $request, User $admin, string $reason): BuyerRequest
    {
        if ($request->status !== BuyerRequestStatus::PendingApproval) {
            throw new RuntimeException(__('This request has already been dealt with.'));
        }

        if (trim($reason) === '') {
            throw new RuntimeException(__('Tell the buyer why.'));
        }

        $request->forceFill([
            'status' => BuyerRequestStatus::Rejected,
            'approved_by' => $admin->getKey(),
            'rejection_reason' => trim($reason),
        ])->save();

        $this->notifier->requestReviewed($request, approved: false);

        return $request;
    }

    /**
     * The buyer closes it early — they found what they wanted elsewhere, or
     * changed their mind.
     */
    public function close(BuyerRequest $request, User $buyer): BuyerRequest
    {
        if ($request->user_id !== $buyer->getKey()) {
            throw new RuntimeException(__('That request is not yours.'));
        }

        if ($request->status->isFinished()) {
            throw new RuntimeException(__('This request is already closed.'));
        }

        return DB::transaction(function () use ($request): BuyerRequest {
            $request->forceFill([
                'status' => BuyerRequestStatus::Closed,
                'closed_at' => now(),
            ])->save();

            // Sellers waiting on an answer deserve one, even when the answer
            // is that the buyer has gone.
            $this->rejectOutstanding($request, __('The buyer closed this request.'));

            return $request;
        });
    }

    /**
     * Close everything that has run out.
     *
     * @return array{expired: int, warned: int}
     */
    public function sweep(): array
    {
        return [
            'expired' => $this->expireDue(),
            'warned' => $this->warnExpiring(),
        ];
    }

    public function expireDue(): int
    {
        $expired = 0;

        BuyerRequest::query()->dueToExpire()->chunkById(100, function ($requests) use (&$expired): void {
            foreach ($requests as $request) {
                DB::transaction(function () use ($request): void {
                    $request->forceFill([
                        'status' => BuyerRequestStatus::Expired,
                        'closed_at' => now(),
                    ])->save();

                    $this->rejectOutstanding($request, __('This request closed before anything was agreed.'));
                });

                $expired++;
            }
        });

        return $expired;
    }

    /**
     * Warn buyers whose requests close soon.
     *
     * `expiry_warned_at` is stamped so the scheduler saying the same thing
     * every hour for three days does not become the reason somebody stops
     * reading our email.
     */
    public function warnExpiring(): int
    {
        $days = $this->warningDays();
        $warned = 0;

        BuyerRequest::query()
            ->where('status', BuyerRequestStatus::Open)
            ->whereNull('expiry_warned_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->chunkById(100, function ($requests) use (&$warned): void {
                foreach ($requests as $request) {
                    $request->forceFill(['expiry_warned_at' => now()])->save();

                    $this->notifier->requestExpiring(
                        $request,
                        max(1, (int) ceil(now()->diffInDays($request->expires_at, absolute: true))),
                    );

                    $warned++;
                }
            });

        return $warned;
    }

    /**
     * Turn down every offer still waiting on a request that has closed.
     */
    private function rejectOutstanding(BuyerRequest $request, string $reason): void
    {
        $request->offers()->open()->get()->each(function ($offer) use ($reason): void {
            $offer->forceFill([
                'status' => OfferStatus::Rejected,
                'responded_at' => now(),
            ])->save();

            $this->notifier->offerRejected($offer, $reason);
        });
    }
}
