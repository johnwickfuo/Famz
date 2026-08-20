<?php

namespace App\Services\Disputes;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\EngagementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\SubOrderStatus;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\MentorshipEngagement;
use App\Models\MentorshipInvoice;
use App\Models\SubOrder;
use App\Models\User;
use App\Notifications\DisputeRaised;
use App\Services\Mentorship\EngagementService;
use App\Services\Orders\FulfilmentService;
use App\Services\Settlement\SettlementManager;
use App\Services\Wallet\WalletService;
use App\Support\Commission;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Raising, arguing and settling disputes.
 *
 * The arithmetic here obeys one rule, and every resolution path is checked
 * against it: **money is neither created nor destroyed**. For a sub-order the
 * buyer paid G for,
 *
 *     what the seller ends up with
 *   + what the platform ends up with
 *   + what goes back to the buyer
 *   = G
 *
 * That is what makes a resolution auditable. A path that quietly leaves a few
 * naira unaccounted for is not a rounding problem, it is a hole.
 *
 * The same class handles mentorship disputes, because a dispute is a dispute:
 * one thread, one set of statuses, one admin queue, one conservation law. Only
 * the arithmetic of a refund differs — mentorship has no delivery fee to
 * apportion and no goods to send back — so that part lives in its own methods
 * below rather than in a branch inside the marketplace ones.
 */
class DisputeService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly SettlementManager $settlement,
    ) {}

    /**
     * How long after delivery a buyer may still complain.
     */
    public function windowDays(): int
    {
        return max(0, (int) settings('dispute_window_days', 7));
    }

    /**
     * Whether this buyer can still raise a dispute on this order.
     *
     * The window runs from delivery, not from payment: a buyer waiting three
     * weeks for day-old chicks should not lose the right to complain about
     * them before they arrive.
     */
    public function canRaise(SubOrder $subOrder, User $buyer): bool
    {
        if ($subOrder->order->user_id !== $buyer->getKey()) {
            return false;
        }

        if (! $subOrder->order->isPaid()) {
            return false;
        }

        if ($this->liveDisputeFor($subOrder) !== null) {
            return false;
        }

        // Nothing to argue about on an order the seller already refunded.
        if (in_array($subOrder->status, [
            SubOrderStatus::Rejected,
            SubOrderStatus::Refunded,
            SubOrderStatus::Cancelled,
        ], true)) {
            return false;
        }

        // Before delivery there is no deadline: the complaint is that it has
        // not come.
        if ($subOrder->delivered_at === null) {
            return true;
        }

        return $subOrder->delivered_at->copy()->addDays($this->windowDays())->isFuture();
    }

    /**
     * When the right to dispute this order runs out.
     */
    public function windowClosesAt(SubOrder $subOrder): ?Carbon
    {
        return $subOrder->delivered_at?->copy()->addDays($this->windowDays());
    }

    public function liveDisputeFor(SubOrder $subOrder): ?Dispute
    {
        return Dispute::query()
            ->where('sub_order_id', $subOrder->getKey())
            ->live()
            ->first();
    }

    /**
     * A buyer says something went wrong.
     *
     * This freezes the money. `FulfilmentService::dispute()` clears the
     * auto-release timestamp and moves the sub-order into Disputed, and the
     * settlement drivers refuse to release a disputed order — so from here
     * nothing moves until somebody decides.
     *
     * @param  array<int, string>  $evidenceImages
     */
    public function raise(
        SubOrder $subOrder,
        User $buyer,
        DisputeReason $reason,
        string $description,
        array $evidenceImages = [],
    ): Dispute {
        if (trim($description) === '') {
            throw new RuntimeException(__('Tell us what went wrong.'));
        }

        if (! $this->canRaise($subOrder, $buyer)) {
            throw new RuntimeException(
                $this->liveDisputeFor($subOrder) !== null
                    ? __('There is already an open dispute on this order.')
                    : __('The time to dispute this order has passed.')
            );
        }

        $dispute = DB::transaction(function () use ($subOrder, $buyer, $reason, $description, $evidenceImages): Dispute {
            $dispute = new Dispute;
            $dispute->forceFill([
                'sub_order_id' => $subOrder->getKey(),
                'raised_by' => $buyer->getKey(),
                'reason' => $reason,
                'description' => trim($description),
                'evidence_images' => $evidenceImages === [] ? null : array_values($evidenceImages),
                'status' => DisputeStatus::Open,
            ])->save();

            // Freezing the funds is the point of all this.
            app(FulfilmentService::class)
                ->dispute($subOrder, $reason->label(), $buyer);

            $this->comment($dispute, $buyer, trim($description));

            return $dispute->refresh();
        });

        $this->tellTheOtherParty($dispute, $buyer);

        return $dispute;
    }

    /**
     * Tell whoever now has a clock running.
     *
     * Sent after the transaction, never inside it: a queued job can be picked
     * up by a worker before the commit lands, and an email about a dispute the
     * database does not have yet is worse than a late one.
     *
     * The raiser is excluded. Somebody who has just filled in a form does not
     * need an email telling them they filled in a form.
     */
    private function tellTheOtherParty(Dispute $dispute, User $raiser): void
    {
        $other = $dispute->isMentorship()
            ? $this->engagementCounterparty($dispute, $raiser)
            : $dispute->subOrder?->seller?->user;

        if ($other === null || $other->is($raiser)) {
            return;
        }

        $other->notify(new DisputeRaised($dispute, route('disputes.show', $dispute)));
    }

    private function engagementCounterparty(Dispute $dispute, User $raiser): ?User
    {
        $engagement = $dispute->engagement;

        if ($engagement === null) {
            return null;
        }

        $mentorUser = $engagement->mentor?->user;

        // Either side may raise it, so the counterparty is "whichever of the
        // two is not the person who just filled in the form".
        return $mentorUser !== null && $mentorUser->is($raiser)
            ? $engagement->client
            : $mentorUser;
    }

    /**
     * Add to the thread.
     *
     * @param  array<int, string>  $attachments
     */
    public function comment(
        Dispute $dispute,
        User $author,
        string $body,
        array $attachments = [],
        bool $internal = false,
    ): DisputeMessage {
        if (trim($body) === '') {
            throw new RuntimeException(__('Write something first.'));
        }

        $message = new DisputeMessage;
        $message->forceFill([
            'dispute_id' => $dispute->getKey(),
            'user_id' => $author->getKey(),
            'body' => trim($body),
            'attachments' => $attachments === [] ? null : array_values($attachments),
            'is_internal' => $internal,
        ])->save();

        return $message;
    }

    public function markUnderReview(Dispute $dispute, User $admin): Dispute
    {
        $this->assertLive($dispute);

        $dispute->forceFill(['status' => DisputeStatus::UnderReview])->save();

        return $dispute;
    }

    // -----------------------------------------------------------------------
    // Mentorship
    // -----------------------------------------------------------------------

    /**
     * How long after an engagement finishes either side may still complain.
     */
    public function engagementWindowDays(): int
    {
        return max(0, (int) settings('mentorship_dispute_window_days', 7));
    }

    public function liveDisputeForEngagement(MentorshipEngagement $engagement): ?Dispute
    {
        return Dispute::query()
            ->where('mentorship_engagement_id', $engagement->getKey())
            ->live()
            ->first();
    }

    /**
     * Whether this person may complain about this engagement.
     *
     * EITHER party, unlike the marketplace where only a buyer may raise one. A
     * mentor whose client has vanished after taking three months of advice has
     * a complaint worth hearing, and no other way to make it.
     */
    public function canRaiseOnEngagement(MentorshipEngagement $engagement, User $user): bool
    {
        $isParty = $engagement->client_id === $user->getKey()
            || $engagement->mentor?->user_id === $user->getKey();

        if (! $isParty) {
            return false;
        }

        // Nothing to argue about before any money has moved.
        if ($engagement->paidInvoices()->count() < 1) {
            return false;
        }

        if ($this->liveDisputeForEngagement($engagement) !== null) {
            return false;
        }

        if ($engagement->status->isLive()) {
            return $engagement->status !== EngagementStatus::Disputed;
        }

        // After it finished, only inside the window.
        return $engagement->completed_at !== null
            && $engagement->completed_at->copy()->addDays($this->engagementWindowDays())->isFuture();
    }

    /**
     * Somebody says the engagement went wrong.
     *
     * This freezes it: moving to Disputed takes it out of the auto-confirm
     * sweep, so a client who complains on day six does not have the work
     * confirmed out from under them on day seven.
     *
     * @param  array<int, string>  $evidenceImages
     */
    public function raiseOnEngagement(
        MentorshipEngagement $engagement,
        User $raiser,
        DisputeReason $reason,
        string $description,
        array $evidenceImages = [],
    ): Dispute {
        if (trim($description) === '') {
            throw new RuntimeException(__('Tell us what went wrong.'));
        }

        if (! $this->canRaiseOnEngagement($engagement, $raiser)) {
            throw new RuntimeException(
                $this->liveDisputeForEngagement($engagement) !== null
                    ? __('There is already an open dispute on this engagement.')
                    : __('This engagement cannot be disputed.')
            );
        }

        $dispute = DB::transaction(function () use ($engagement, $raiser, $reason, $description, $evidenceImages): Dispute {
            $dispute = new Dispute;

            $dispute->forceFill([
                'mentorship_engagement_id' => $engagement->getKey(),
                'raised_by' => $raiser->getKey(),
                'reason' => $reason,
                'description' => trim($description),
                'evidence_images' => $evidenceImages === [] ? null : array_values($evidenceImages),
                'status' => DisputeStatus::Open,
            ])->save();

            // Out of the auto-confirm sweep, and out of reach of a release.
            $engagement->forceFill([
                'status' => EngagementStatus::Disputed,
                'auto_confirm_at' => null,
            ])->save();

            $this->comment($dispute, $raiser, trim($description));

            return $dispute->refresh();
        });

        $this->tellTheOtherParty($dispute, $raiser);

        return $dispute;
    }

    /**
     * Decided for the mentor: they are paid.
     *
     * Nothing moves between accounts — the money is already sitting against
     * this engagement's invoices, it was only frozen — so this path is balanced
     * by having nothing to balance.
     */
    public function resolveEngagementForMentor(Dispute $dispute, User $admin, string $note): Dispute
    {
        $this->assertLive($dispute);

        return DB::transaction(function () use ($dispute, $admin, $note): Dispute {
            $engagement = $dispute->engagement;

            foreach ($engagement->invoices()->where('status', InvoiceStatus::Paid)->get() as $invoice) {
                app(EngagementService::class)->release($invoice);
            }

            $engagement->forceFill([
                'status' => EngagementStatus::Completed,
                'completed_at' => $engagement->completed_at ?? now(),
                'client_confirmed_at' => $engagement->client_confirmed_at ?? now(),
            ])->save();

            $engagement->mentor?->refreshStandings();

            return $this->close($dispute, $admin, DisputeStatus::ResolvedSeller, $note, 0);
        });
    }

    /**
     * Decided for the client: everything paid goes back.
     */
    public function resolveEngagementForClient(Dispute $dispute, User $admin, string $note): Dispute
    {
        $this->assertLive($dispute);

        return $this->refundEngagement(
            $dispute,
            $admin,
            $dispute->amountAtStakeKobo(),
            $note,
            DisputeStatus::ResolvedBuyer,
        );
    }

    /**
     * Split the difference on an engagement.
     */
    public function resolveEngagementPartially(Dispute $dispute, User $admin, int $refundKobo, string $note): Dispute
    {
        $this->assertLive($dispute);

        $stake = $dispute->amountAtStakeKobo();

        if ($refundKobo <= 0) {
            throw new RuntimeException(__('A partial refund has to be more than nothing. Decide for the mentor instead.'));
        }

        if ($refundKobo >= $stake) {
            throw new RuntimeException(__('That is everything paid. Refund it in full instead.'));
        }

        return $this->refundEngagement($dispute, $admin, $refundKobo, $note, DisputeStatus::ResolvedPartial);
    }

    /**
     * Move `$refundKobo` back to the client.
     *
     * Taken from the mentor and the platform in the same proportion as the
     * original split, so neither pays for the other's share of a compromise —
     * refund a third of the engagement and the platform gives up a third of its
     * commission, no more and no less.
     *
     * Held entries and their counter-entries cancel where they sit; anything
     * already released is released first on a partial so the two sides of the
     * correction end up in the same state, exactly as on a sub-order.
     */
    private function refundEngagement(
        Dispute $dispute,
        User $admin,
        int $refundKobo,
        string $note,
        DisputeStatus $status,
    ): Dispute {
        return DB::transaction(function () use ($dispute, $admin, $refundKobo, $note, $status): Dispute {
            $engagement = $dispute->engagement;
            $stake = $engagement->paidToDateKobo();
            $engagements = app(EngagementService::class);

            if ($refundKobo < $stake) {
                foreach ($engagement->invoices()->where('status', InvoiceStatus::Paid)->get() as $invoice) {
                    $engagements->release($invoice);
                }
            }

            $split = $this->shareOfEngagementRefund($engagement, $refundKobo);

            // Newest period first: a refund on a monthly engagement is nearly
            // always about the month just gone.
            $invoices = $engagement->invoices()
                ->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Released])
                ->orderByDesc('sequence')
                ->get();

            $anchor = $invoices->first();

            $this->clawBackEngagement(
                $anchor,
                $engagement->mentor->user_id,
                $split['mentor_kobo'],
                $note,
                $admin,
                $refundKobo >= $stake,
            );

            $this->clawBackEngagement(
                $anchor,
                null,
                $split['platform_kobo'],
                $note,
                $admin,
                $refundKobo >= $stake,
            );

            $this->wallet->record(
                user: $engagement->client_id,
                type: LedgerType::Refund,
                amountKobo: $refundKobo,
                // Refunded: it counts toward no balance, because the money goes
                // back to their card rather than into a wallet.
                state: LedgerState::Refunded,
                description: __('Dispute refund on :reference', ['reference' => $engagement->reference]),
                meta: [
                    'dispute_id' => $dispute->getKey(),
                    'engagement_reference' => $engagement->reference,
                    'of_total_kobo' => $stake,
                    'from_mentor_kobo' => $split['mentor_kobo'],
                    'from_platform_kobo' => $split['platform_kobo'],
                ],
                createdBy: $admin->getKey(),
                invoice: $anchor,
            );

            $full = $refundKobo >= $stake;

            if ($full) {
                $engagement->invoices()
                    ->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Released])
                    ->update(['status' => InvoiceStatus::Refunded, 'updated_at' => now()]);
            }

            $engagement->forceFill([
                'status' => $full ? EngagementStatus::Refunded : EngagementStatus::Completed,
                'completed_at' => $engagement->completed_at ?? now(),
                'auto_confirm_at' => null,
            ])->save();

            $engagement->mentor?->refreshStandings();

            return $this->close($dispute, $admin, $status, $note, $refundKobo);
        });
    }

    /**
     * Take money back off one account, in the state it is actually sitting in.
     */
    private function clawBackEngagement(
        ?MentorshipInvoice $invoice,
        ?int $userId,
        int $amountKobo,
        string $reason,
        User $admin,
        bool $full,
    ): void {
        if ($amountKobo <= 0 || $invoice === null) {
            return;
        }

        $this->wallet->record(
            user: $userId,
            type: LedgerType::Reversal,
            amountKobo: -$amountKobo,
            // On a full refund the money never left escrow, so the correction
            // is made there; on a partial everything was released first, so it
            // is made in released.
            state: $full ? LedgerState::Held : LedgerState::Released,
            description: __('Dispute on :reference: :reason', [
                'reference' => $invoice->engagement?->reference ?? $invoice->reference,
                'reason' => $reason,
            ]),
            createdBy: $admin->getKey(),
            invoice: $invoice,
        );
    }

    /**
     * How an engagement refund is shared between the mentor and the platform.
     *
     * Straight proportion of the original split, with the mentor's share taken
     * by subtraction so the two always add back to the refund exactly.
     *
     * @return array{mentor_kobo: int, platform_kobo: int}
     */
    private function shareOfEngagementRefund(MentorshipEngagement $engagement, int $refundKobo): array
    {
        $split = Commission::on($refundKobo, (float) $engagement->commission_percent_snapshot);

        return [
            'platform_kobo' => $split->commissionKobo,
            'mentor_kobo' => $refundKobo - $split->commissionKobo,
        ];
    }

    // -----------------------------------------------------------------------
    // Resolutions
    // -----------------------------------------------------------------------

    /**
     * Decided for the seller: they keep everything.
     *
     * No ledger entries are written at all — the money is already sitting in
     * the seller's and the platform's accounts, it was only frozen. Unfreezing
     * it moves nothing between accounts, so this path is balanced by having
     * nothing to balance.
     */
    public function resolveForSeller(Dispute $dispute, User $admin, string $note): Dispute
    {
        $this->assertLive($dispute);

        return DB::transaction(function () use ($dispute, $admin, $note): Dispute {
            $subOrder = $dispute->subOrder;

            // Back out of Disputed first: the drivers refuse to release a
            // disputed order, which is exactly what we want them to do right
            // up until this moment.
            $subOrder->forceFill([
                'status' => SubOrderStatus::Delivered,
                'disputed_at' => null,
            ])->save();

            $this->settlement->driver()->release($subOrder->fresh(), $admin);

            return $this->close($dispute, $admin, DisputeStatus::ResolvedSeller, $note, 0);
        });
    }

    /**
     * Decided for the buyer: everything goes back.
     */
    public function resolveForBuyer(Dispute $dispute, User $admin, string $note): Dispute
    {
        $this->assertLive($dispute);

        return $this->refund($dispute, $admin, $dispute->amountAtStakeKobo(), $note, DisputeStatus::ResolvedBuyer);
    }

    /**
     * Split the difference.
     *
     * The refund comes out of the seller's credit and the platform's
     * commission in the same proportion as the original split, so neither pays
     * for the other's share of a compromise.
     */
    public function resolvePartially(Dispute $dispute, User $admin, int $refundKobo, string $note): Dispute
    {
        $this->assertLive($dispute);

        $stake = $dispute->amountAtStakeKobo();

        if ($refundKobo <= 0) {
            throw new RuntimeException(__('A partial refund has to be more than nothing. Decide for the seller instead.'));
        }

        if ($refundKobo >= $stake) {
            throw new RuntimeException(__('That is the whole order. Refund it in full instead.'));
        }

        return $this->refund($dispute, $admin, $refundKobo, $note, DisputeStatus::ResolvedPartial);
    }

    /**
     * Closed with no money moving — withdrawn, duplicate, or abandoned.
     *
     * The funds unfreeze on the seller's side, exactly as if the dispute had
     * never been raised: the escrow clock restarts rather than the money being
     * released outright, because nobody has actually decided anything.
     */
    public function closeWithoutDecision(Dispute $dispute, User $admin, string $note): Dispute
    {
        $this->assertLive($dispute);

        return DB::transaction(function () use ($dispute, $admin, $note): Dispute {
            $subOrder = $dispute->subOrder;

            $subOrder->forceFill([
                'status' => $subOrder->delivered_at !== null
                    ? SubOrderStatus::Delivered
                    : SubOrderStatus::Accepted,
                'disputed_at' => null,
                'auto_release_at' => $subOrder->delivered_at !== null
                    ? $this->settlement->driver()->autoReleaseAt($subOrder)
                    : null,
            ])->save();

            $subOrder->order->syncStatusFromSubOrders();

            return $this->close($dispute, $admin, DisputeStatus::Closed, $note, 0);
        });
    }

    // -----------------------------------------------------------------------

    /**
     * Move `$refundKobo` back to the buyer, out of the seller's and the
     * platform's shares.
     *
     * Held entries are cancelled and rewritten rather than adjusted, because
     * held money never counted toward a balance and a partial hold is not a
     * thing the ledger models. Released entries get counter-entries beside
     * them, leaving the original intact.
     */
    private function refund(
        Dispute $dispute,
        User $admin,
        int $refundKobo,
        string $note,
        DisputeStatus $status,
    ): Dispute {
        return DB::transaction(function () use ($dispute, $admin, $refundKobo, $note, $status): Dispute {
            $subOrder = $dispute->subOrder;
            $stake = $subOrder->grandTotalKobo();

            /*
             * On a partial refund the money is released first and clawed back
             * afterwards, so the original entry and its counter-entry sit in
             * the same state and cancel. Releasing afterwards would move only
             * the original and leave the clawback stranded in escrow, which
             * shows up as a seller with a negative held balance.
             *
             * On a full refund nothing is released: the held entries and their
             * counter-entries cancel where they are.
             */
            if ($refundKobo < $stake) {
                $this->wallet->releaseHeld($subOrder);
            }

            // How the refund is shared out. Commission is charged on goods
            // only, so the split follows the goods, and the delivery fee comes
            // wholly out of the seller's side.
            $split = $this->shareOfRefund($subOrder, $refundKobo);

            $this->clawBack($subOrder, $subOrder->seller->user_id, $split['seller_kobo'], $note, $admin);
            $this->clawBack($subOrder, null, $split['platform_kobo'], $note, $admin);

            // What the buyer gets back. In a state that counts toward no
            // balance: the money returns to their card, not to a wallet.
            $this->wallet->record(
                user: $subOrder->order->user_id,
                type: LedgerType::Refund,
                amountKobo: $refundKobo,
                state: LedgerState::Refunded,
                description: __('Dispute refund on :reference', ['reference' => $subOrder->reference]),
                subOrder: $subOrder,
                meta: [
                    'dispute_id' => $dispute->getKey(),
                    'of_total_kobo' => $stake,
                    'from_seller_kobo' => $split['seller_kobo'],
                    'from_platform_kobo' => $split['platform_kobo'],
                ],
                createdBy: $admin->getKey(),
            );

            // Whatever the seller keeps is now theirs; there is nothing left
            // to argue about.
            $subOrder->forceFill([
                'status' => $refundKobo >= $stake ? SubOrderStatus::Refunded : SubOrderStatus::Settled,
                'disputed_at' => null,
                'auto_release_at' => null,
                'settled_at' => now(),
            ])->save();

            $subOrder->order->syncStatusFromSubOrders();

            return $this->close($dispute, $admin, $status, $note, $refundKobo);
        });
    }

    /**
     * Take `$amountKobo` back off one account's entries for this sub-order.
     */
    private function clawBack(
        SubOrder $subOrder,
        ?int $userId,
        int $amountKobo,
        string $reason,
        User $admin,
    ): void {
        if ($amountKobo <= 0) {
            return;
        }

        $this->wallet->record(
            user: $userId,
            type: LedgerType::Reversal,
            amountKobo: -$amountKobo,
            // Matching the state the money is sitting in, so the two cancel:
            // held money is cancelled while held, released money while
            // released.
            state: $this->stateOfFundsFor($subOrder, $userId),
            description: __('Dispute on :reference: :reason', [
                'reference' => $subOrder->reference,
                'reason' => $reason,
            ]),
            subOrder: $subOrder,
            createdBy: $admin->getKey(),
        );
    }

    /**
     * Whether this account's money for this sub-order is still held.
     */
    private function stateOfFundsFor(SubOrder $subOrder, ?int $userId): LedgerState
    {
        $held = $this->wallet->entriesFor($subOrder, $userId)
            ->firstWhere('state', LedgerState::Held);

        return $held !== null ? LedgerState::Held : LedgerState::Released;
    }

    /**
     * How a refund is shared between the seller and the platform.
     *
     * A refund is counted against the goods first and the delivery fee only
     * once the goods are exhausted. Commission was charged on goods alone, so
     * this is the rule that gives the platform back exactly its cut of what is
     * being returned: refund two bags out of ten and the platform gives up the
     * commission on two bags, no more and no less.
     *
     * The consequence is deliberate: a refund that only concerns the delivery
     * — it came late, it came cold — comes out of the seller, who is the one
     * who delivered it late. The platform never taxed the fee and does not
     * profit from the complaint either way.
     *
     * Integer arithmetic throughout, with the seller's share taken by
     * subtraction, so the two always add back to the refund exactly.
     *
     * @return array{seller_kobo: int, platform_kobo: int}
     */
    private function shareOfRefund(SubOrder $subOrder, int $refundKobo): array
    {
        $goodsPart = min($refundKobo, $subOrder->subtotal_kobo);

        $split = Commission::on($goodsPart, (float) $subOrder->commission_percent_snapshot);

        // Never more than the platform actually took, whatever the rounding.
        $platform = min($split->commissionKobo, $subOrder->commission_amount_kobo);

        return [
            'platform_kobo' => $platform,
            'seller_kobo' => $refundKobo - $platform,
        ];
    }

    private function close(
        Dispute $dispute,
        User $admin,
        DisputeStatus $status,
        string $note,
        int $refundKobo,
    ): Dispute {
        $dispute->forceFill([
            'status' => $status,
            'resolution_note' => trim($note),
            'refund_amount_kobo' => $refundKobo,
            'resolved_by' => $admin->getKey(),
            'resolved_at' => now(),
        ])->save();

        $this->comment($dispute, $admin, $this->decisionLine($dispute, $status, $refundKobo, $note));

        return $dispute->refresh();
    }

    /**
     * The line written into the thread when a decision is made.
     *
     * Worded for whichever kind of dispute this is: telling a mentor that "the
     * seller" keeps the money is the sort of thing that makes people distrust
     * a decision that was actually correct.
     */
    private function decisionLine(Dispute $dispute, DisputeStatus $status, int $refundKobo, string $note): string
    {
        $paid = $dispute->isMentorship() ? __('the client') : __('the buyer');
        $paidTo = $dispute->isMentorship() ? __('the mentor') : __('the seller');

        $decision = match ($status) {
            DisputeStatus::ResolvedBuyer => __('Refunded in full: :amount goes back to :party.', [
                'amount' => Money::fromKobo($refundKobo),
                'party' => $paid,
            ]),
            DisputeStatus::ResolvedSeller => __('Decided for :party. The payment is released to them.', [
                'party' => $paidTo,
            ]),
            DisputeStatus::ResolvedPartial => __(':amount goes back to :party; :other keeps the rest.', [
                'amount' => Money::fromKobo($refundKobo),
                'party' => $paid,
                'other' => $paidTo,
            ]),
            default => __('Closed with no money moved.'),
        };

        return trim($decision."\n\n".trim($note));
    }

    private function assertLive(Dispute $dispute): void
    {
        if (! $dispute->isLive()) {
            throw new RuntimeException(__('This dispute has already been settled.'));
        }
    }
}
