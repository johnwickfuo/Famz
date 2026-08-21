<?php

namespace App\Services\Mentorship;

use App\Enums\BillingType;
use App\Enums\EngagementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\MentorshipEngagement;
use App\Models\MentorshipInvoice;
use App\Models\MentorshipMatch;
use App\Models\MentorshipPackage;
use App\Models\Order;
use App\Models\User;
use App\Notifications\EngagementConfirmed;
use App\Services\Wallet\WalletService;
use App\Support\Commission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Hiring a mentor, and the money that follows.
 *
 * The shape of it:
 *
 *   request  → engagement (pending_payment) + invoice 1 (unpaid)
 *   pay      → invoice paid; mentor's share HELD, platform's commission HELD;
 *              engagement becomes active and the two people can finally see
 *              each other's contact details
 *   finish   → mentor marks it done; the client confirms, or seven days pass
 *   release  → every paid invoice moves from held to released
 *
 * Two decisions are worth stating plainly.
 *
 * First, the contact reveal is a function of the engagement's STATUS. There is
 * no separate "unlocked" flag that could disagree with the payment, and every
 * reveal in the application asks the same question through
 * `EngagementStatus::revealsContact()`.
 *
 * Second, a periodic engagement releases per confirmed period, not per term. A
 * mentor is paid for the month they have finished, and a client who stops
 * paying in month three has never paid for month three. That is why every
 * mentorship ledger entry hangs off an invoice rather than off the engagement.
 */
class EngagementService
{
    public function __construct(private readonly WalletService $wallet) {}

    public function commissionPercent(): float
    {
        return (float) settings('mentorship_commission_percent', 15);
    }

    public function confirmationDays(): int
    {
        return max(1, (int) settings('mentorship_confirmation_days', 7));
    }

    /**
     * A client asks to hire a mentor on a package.
     *
     * Nothing is revealed and nothing is charged yet: this creates the
     * engagement and the first invoice, both unpaid.
     */
    public function request(
        User $client,
        MentorshipPackage $package,
        string $brief = '',
        ?MentorshipMatch $match = null,
    ): MentorshipEngagement {
        $mentor = $package->mentor;

        if ($mentor === null || ! $mentor->isBookable()) {
            throw new RuntimeException(__('This mentor is not taking new work at the moment.'));
        }

        if (! $package->is_active) {
            throw new RuntimeException(__('That package is no longer offered.'));
        }

        if ($mentor->user_id === $client->getKey()) {
            throw new RuntimeException(__('You cannot hire yourself.'));
        }

        // One live engagement per client per mentor. Two at once would make
        // "is the contact unlocked" ambiguous, and it is never what somebody
        // meant to do.
        $existing = MentorshipEngagement::query()
            ->ownedBy($client)
            ->where('mentor_profile_id', $mentor->getKey())
            ->live()
            ->first();

        if ($existing !== null) {
            throw new RuntimeException(__('You already have an engagement running with this mentor.'));
        }

        return DB::transaction(function () use ($client, $package, $mentor, $brief, $match): MentorshipEngagement {
            $split = Commission::on($package->price_kobo, $this->commissionPercent());

            $engagement = new MentorshipEngagement;

            $engagement->forceFill([
                'client_id' => $client->getKey(),
                'mentor_profile_id' => $mentor->getKey(),
                'mentorship_package_id' => $package->getKey(),

                // Snapshots, all of them. The package may be renamed, repriced
                // or withdrawn tomorrow; this engagement keeps its terms.
                'package_title' => $package->title,
                'package_description' => $package->description,
                'billing_type' => $package->billing_type,
                'billing_interval' => $package->billing_interval,
                'price_kobo' => $package->price_kobo,
                'currency' => $package->currency,
                'commission_percent_snapshot' => $this->commissionPercent(),
                'platform_amount_kobo' => $split->commissionKobo,
                'mentor_amount_kobo' => $split->payoutKobo,

                'brief' => trim($brief) ?: null,
                'mentorship_match_id' => $match?->getKey(),
                'status' => EngagementStatus::PendingPayment,
            ])->save();

            $this->openInvoice($engagement->refresh(), 1, now());

            return $engagement->refresh();
        });
    }

    /**
     * Open the next unpaid invoice for a period.
     */
    public function openInvoice(MentorshipEngagement $engagement, int $sequence, Carbon $start): MentorshipInvoice
    {
        $end = $engagement->isPeriodic()
            ? $engagement->billing_interval->endOf($start)
            : null;

        $invoice = new MentorshipInvoice;

        $invoice->forceFill([
            'mentorship_engagement_id' => $engagement->getKey(),
            'sequence' => $sequence,
            'period_start' => $engagement->isPeriodic() ? $start->toDateString() : null,
            'period_end' => $end?->toDateString(),
            'amount_kobo' => $engagement->price_kobo,
            'platform_amount_kobo' => $engagement->platform_amount_kobo,
            'mentor_amount_kobo' => $engagement->mentor_amount_kobo,
            'currency' => $engagement->currency,
            'status' => InvoiceStatus::PendingPayment,
            'due_at' => $start,
        ])->save();

        return $invoice->refresh();
    }

    /**
     * The money arrived.
     *
     * Called from the payment layer once a webhook has been verified — never
     * from a controller, and never on the strength of a redirect back from a
     * gateway. Idempotent: a webhook delivered three times writes one set of
     * entries.
     */
    public function markInvoicePaid(MentorshipInvoice $invoice, ?Order $order = null): bool
    {
        return DB::transaction(function () use ($invoice, $order): bool {
            $locked = MentorshipInvoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isPaid()) {
                return false;
            }

            $engagement = $locked->engagement;

            $locked->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
                'order_id' => $order?->getKey() ?? $locked->order_id,
                'order_reference' => $order?->reference ?? $locked->order_reference,
            ])->save();

            /*
             * Held, both of them. The platform's cut is held as well as the
             * mentor's: until the work is confirmed, the platform has not
             * earned its commission either, and showing it as revenue on day
             * one would overstate what the business can actually spend.
             */
            $this->wallet->record(
                user: $engagement->mentor->user_id,
                type: LedgerType::MentorshipEarning,
                amountKobo: $locked->mentor_amount_kobo,
                state: LedgerState::Held,
                description: __('Mentorship: :package (:reference)', [
                    'package' => $engagement->package_title,
                    'reference' => $locked->reference,
                ]),
                meta: $this->meta($engagement, $locked),
                invoice: $locked,
            );

            if ($locked->platform_amount_kobo > 0) {
                $this->wallet->record(
                    user: null,
                    type: LedgerType::Commission,
                    amountKobo: $locked->platform_amount_kobo,
                    state: LedgerState::Held,
                    description: __('Mentorship commission on :reference', ['reference' => $locked->reference]),
                    meta: $this->meta($engagement, $locked),
                    invoice: $locked,
                );
            }

            /*
             * The moment the contact details become visible — to both of them,
             * at once. Nothing else in the application unlocks them.
             */
            if ($engagement->status === EngagementStatus::PendingPayment) {
                $engagement->forceFill([
                    'status' => EngagementStatus::Active,
                    'started_at' => now(),
                ])->save();
            }

            return true;
        });
    }

    /**
     * Both sides hear that the engagement is live.
     *
     * Called after markInvoicePaid commits rather than inside it, for the same
     * reason as everywhere else: a notification is not worth rolling back a
     * payment for. The news is not really "you have been charged" — it is that
     * the contact details are visible now, which is the one thing neither of
     * them could see a moment ago.
     */
    public function announceActivation(MentorshipEngagement $engagement): void
    {
        $engagement->loadMissing('client', 'mentor');

        foreach ([[$engagement->client, false], [$engagement->mentor, true]] as [$user, $forMentor]) {
            try {
                $user?->notify(new EngagementConfirmed($engagement, $forMentor));
            } catch (\Throwable $exception) {
                Log::error('An engagement notification could not be sent.', [
                    'engagement' => $engagement->reference,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * The mentor says the work is done.
     *
     * Nothing is released yet. What starts is the clock: the client has a
     * configurable window to confirm or dispute, and silence past it counts as
     * agreement.
     */
    public function markComplete(MentorshipEngagement $engagement, User $mentorUser): MentorshipEngagement
    {
        if ($engagement->mentor?->user_id !== $mentorUser->getKey()) {
            throw new RuntimeException(__('This is not your engagement.'));
        }

        if ($engagement->status !== EngagementStatus::Active) {
            throw new RuntimeException(match ($engagement->status) {
                EngagementStatus::AwaitingConfirmation => __('You have already marked this finished.'),
                EngagementStatus::Disputed => __('This engagement is in dispute.'),
                default => __('This engagement is not running.'),
            });
        }

        if ($engagement->paidInvoices()->count() < 1) {
            throw new RuntimeException(__('Nothing has been paid for on this engagement yet.'));
        }

        $engagement->forceFill([
            'status' => EngagementStatus::AwaitingConfirmation,
            'mentor_marked_complete_at' => now(),
            'auto_confirm_at' => now()->addDays($this->confirmationDays()),
        ])->save();

        return $engagement->refresh();
    }

    /**
     * The client agrees — or the window ran out.
     *
     * This is the only place mentorship money moves from held to released, and
     * it releases every paid invoice, so a monthly engagement pays out
     * everything confirmed at once rather than leaving old periods stranded.
     */
    public function confirmComplete(MentorshipEngagement $engagement, ?User $client = null, bool $auto = false): MentorshipEngagement
    {
        if (! $auto && $engagement->client_id !== $client?->getKey()) {
            throw new RuntimeException(__('This is not your engagement.'));
        }

        if ($engagement->status !== EngagementStatus::AwaitingConfirmation) {
            throw new RuntimeException(__('There is nothing to confirm on this engagement.'));
        }

        return DB::transaction(function () use ($engagement, $auto): MentorshipEngagement {
            $released = 0;

            foreach ($engagement->invoices()->where('status', InvoiceStatus::Paid)->get() as $invoice) {
                $released += $this->release($invoice);
            }

            $engagement->forceFill([
                'status' => EngagementStatus::Completed,
                'client_confirmed_at' => now(),
                'auto_confirmed' => $auto,
                'auto_confirm_at' => null,
                'completed_at' => now(),
            ])->save();

            // Recomputed from the rows, never incremented.
            $engagement->mentor?->refreshStandings();

            return $engagement->refresh();
        });
    }

    /**
     * Move one invoice's held entries into released.
     */
    public function release(MentorshipInvoice $invoice): int
    {
        $moved = $invoice->ledgerEntries()
            ->where('state', LedgerState::Held)
            ->whereIn('type', [LedgerType::MentorshipEarning, LedgerType::Commission])
            ->update(['state' => LedgerState::Released, 'updated_at' => now()]);

        $invoice->forceFill(['status' => InvoiceStatus::Released, 'released_at' => now()])->save();

        return $moved;
    }

    /**
     * Everything whose confirmation window has run out.
     *
     * @return int how many were confirmed
     */
    public function autoConfirmDue(): int
    {
        $due = MentorshipEngagement::query()
            ->where('status', EngagementStatus::AwaitingConfirmation)
            ->whereNotNull('auto_confirm_at')
            ->where('auto_confirm_at', '<=', now())
            ->get();

        $confirmed = 0;

        foreach ($due as $engagement) {
            $this->confirmComplete($engagement, null, auto: true);
            $confirmed++;
        }

        return $confirmed;
    }

    /**
     * Bill the next period on a running periodic engagement.
     *
     * Only for engagements whose current period has ended and which have no
     * unpaid invoice already open — nobody should be sent two bills because a
     * scheduler ran twice.
     *
     * @return int how many invoices were opened
     */
    public function openDuePeriods(): int
    {
        $grace = max(0, (int) settings('mentorship_invoice_grace_days', 3));

        $running = MentorshipEngagement::query()
            ->where('status', EngagementStatus::Active)
            ->where('billing_type', BillingType::Periodic)
            ->with('invoices')
            ->get();

        $opened = 0;

        foreach ($running as $engagement) {
            if ($engagement->invoices->where('status', InvoiceStatus::PendingPayment)->isNotEmpty()) {
                continue;
            }

            $last = $engagement->invoices->sortByDesc('sequence')->first();

            if ($last?->period_end === null || $last->period_end->copy()->addDays($grace)->isFuture()) {
                continue;
            }

            $this->openInvoice($engagement, $last->sequence + 1, $last->period_end->copy());
            $opened++;
        }

        return $opened;
    }

    /**
     * Called off before anything was paid.
     */
    public function cancel(MentorshipEngagement $engagement, User $by, string $reason = ''): MentorshipEngagement
    {
        if ($engagement->paidInvoices()->count() > 0) {
            throw new RuntimeException(__('Money has already changed hands. Raise a dispute instead.'));
        }

        if ($engagement->status->isFinished()) {
            throw new RuntimeException(__('This engagement is already closed.'));
        }

        $engagement->forceFill([
            'status' => EngagementStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => trim($reason) ?: null,
        ])->save();

        $engagement->invoices()->where('status', InvoiceStatus::PendingPayment)
            ->update(['status' => InvoiceStatus::Cancelled, 'updated_at' => now()]);

        return $engagement->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(MentorshipEngagement $engagement, MentorshipInvoice $invoice): array
    {
        return [
            'engagement_reference' => $engagement->reference,
            'invoice_reference' => $invoice->reference,
            'invoice_sequence' => $invoice->sequence,
            'mentor_profile_id' => $engagement->mentor_profile_id,
        ];
    }
}
