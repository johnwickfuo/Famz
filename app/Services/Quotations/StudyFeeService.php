<?php

namespace App\Services\Quotations;

use App\Enums\QuotationRequestStatus;
use App\Enums\StudyFeeCreditStatus;
use App\Models\QuotationRequest;
use App\Models\QuotationStudyFee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The fee that turns an enquiry into a job, and its paper trail.
 *
 * Preparing a farm proposal is days of somebody's work — costing housing,
 * equipment, stocking and labour against one particular site. Doing that for
 * everybody who fills in a form is how the service stops being offered, so the
 * fee is a filter as much as it is revenue.
 *
 * What happens to the fee afterwards is a separate question, decided offline
 * and often months later. This class records that decision rather than
 * enforcing it: the company knocks the fee off the first invoice when somebody
 * signs, but the invoice is raised elsewhere and the only thing that has to
 * survive here is who decided what, and why.
 */
class StudyFeeService
{
    /**
     * What the fee costs today.
     */
    public function currentAmountKobo(): int
    {
        return max(0, (int) settings('quotation_study_fee', 5_000_000));
    }

    /**
     * Raise the fee for a request, or return the one already raised.
     *
     * The amount is copied onto the row rather than read from settings later:
     * raising the fee next quarter must not rewrite what somebody paid last
     * quarter.
     */
    public function raiseFor(QuotationRequest $request): QuotationStudyFee
    {
        return DB::transaction(function () use ($request): QuotationStudyFee {
            $existing = $request->studyFee()->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $fee = new QuotationStudyFee;

            $fee->forceFill([
                'quotation_request_id' => $request->getKey(),
                'amount_kobo' => $this->currentAmountKobo(),
                'currency' => $request->currency,
                'credit_status' => StudyFeeCreditStatus::Uncredited,
            ])->save();

            $request->forceFill(['status' => QuotationRequestStatus::StudyFeePending])->save();

            return $fee->refresh();
        });
    }

    /**
     * The money cleared.
     *
     * Called from the payment layer once a webhook has been verified, never
     * from a controller and never on the strength of a redirect back from a
     * gateway. Idempotent: a webhook delivered three times moves this once.
     *
     * This is the moment the request enters the admin's work queue, and that is
     * the whole point of the fee.
     */
    public function markPaid(QuotationStudyFee $fee, ?string $paymentReference = null): bool
    {
        $paid = DB::transaction(function () use ($fee, $paymentReference): bool {
            $locked = QuotationStudyFee::query()->whereKey($fee->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isPaid()) {
                return false;
            }

            $locked->forceFill([
                'paid_at' => now(),
                'payment_reference' => $paymentReference ?? $locked->payment_reference,
            ])->save();

            $request = $locked->request;

            // Only move a request that is still waiting. One reopened or closed
            // by an administrator in the meantime keeps whatever they set.
            if ($request !== null && $request->status->awaitsStudyFee()) {
                $request->forceFill(['status' => QuotationRequestStatus::StudyFeePaid])->save();
            }

            return true;
        });

        // The write above goes through a locked copy, so the caller's instance
        // would otherwise still read as unpaid.
        $fee->refresh();

        return $paid;
    }

    /**
     * Record what was decided about the fee.
     *
     * The acting administrator is required, not optional. A credit decision
     * with no name against it is exactly the record that fails to settle an
     * argument six months later, which is the only reason this table exists.
     */
    public function recordCredit(
        QuotationStudyFee $fee,
        StudyFeeCreditStatus $status,
        User $admin,
        ?string $note = null,
    ): QuotationStudyFee {
        if (! $fee->isPaid()) {
            throw new RuntimeException(__('This study fee has not been paid, so there is nothing to credit.'));
        }

        $note = trim((string) $note) ?: null;

        if ($status->needsNote() && $note === null) {
            throw new RuntimeException(__('Say why. This note is the record if anybody asks about it later.'));
        }

        $fee->forceFill([
            'credit_status' => $status,
            'credited_by' => $admin->getKey(),
            'credited_at' => now(),
            'credit_note' => $note,
        ])->save();

        return $fee->refresh();
    }

    /**
     * Study fees collected against study fees credited, for the money report.
     *
     * "Credited" is money the company decided to give back against a project it
     * won, so it belongs on a different line from a refund — one is the cost of
     * winning work, the other is work that never happened.
     *
     * @return array{collected_kobo: int, collected_count: int, credited_kobo: int, credited_count: int, refunded_kobo: int, refunded_count: int, uncredited_kobo: int, uncredited_count: int, retained_kobo: int}
     */
    public function tally(?Carbon $from = null, ?Carbon $to = null): array
    {
        $scope = fn () => QuotationStudyFee::query()
            ->paid()
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_at', '<=', $to));

        $byStatus = fn (StudyFeeCreditStatus $status) => $scope()->where('credit_status', $status);

        $collectedKobo = (int) $scope()->sum('amount_kobo');
        $refundedKobo = (int) $byStatus(StudyFeeCreditStatus::Refunded)->sum('amount_kobo');

        return [
            'collected_kobo' => $collectedKobo,
            'collected_count' => $scope()->count(),

            'credited_kobo' => (int) $byStatus(StudyFeeCreditStatus::Credited)->sum('amount_kobo'),
            'credited_count' => $byStatus(StudyFeeCreditStatus::Credited)->count(),

            'refunded_kobo' => $refundedKobo,
            'refunded_count' => $byStatus(StudyFeeCreditStatus::Refunded)->count(),

            'uncredited_kobo' => (int) $byStatus(StudyFeeCreditStatus::Uncredited)->sum('amount_kobo'),
            'uncredited_count' => $byStatus(StudyFeeCreditStatus::Uncredited)->count(),

            // What the company actually kept: everything collected less what
            // went back out. A credited fee was still collected — it was
            // discounted against an invoice raised somewhere else entirely.
            'retained_kobo' => $collectedKobo - $refundedKobo,
        ];
    }
}
