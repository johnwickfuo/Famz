<?php

namespace App\Services\Platform;

use App\Models\Consultation;
use App\Models\Enrolment;
use App\Models\JobApplication;
use App\Models\MentorshipEngagement;
use App\Models\Order;
use App\Models\QuotationRequest;
use App\Models\SubOrder;
use App\Models\User;
use App\Support\Money;

/**
 * What one person's home page should show them.
 *
 * The platform has eight services and almost nobody uses all eight. A buyer who
 * has never sold anything should not scroll past an empty seller panel to reach
 * their orders, and a mentor should not have to wonder why there is a job
 * applications heading with nothing under it.
 *
 * So a section is built only when there is a reason to build it, and the reason
 * is always the same shape: the person holds the role, or they have a record of
 * that kind. Holding the role matters as well as having records — a newly
 * approved seller with no orders yet still needs to see their seller section,
 * because that is where they find out there is nothing to do.
 *
 * Every query here is bounded and eager-loaded. This page is the first thing a
 * signed-in user sees on a phone on mobile data, and it is the easiest page in
 * the application to accidentally make forty queries long.
 */
class DashboardService
{
    /**
     * How many rows a section shows before "see all".
     *
     * Small on purpose. This is a place to notice something needs you, not a
     * place to work through a backlog — every section links to the screen
     * built for that.
     */
    private const PREVIEW_ROWS = 3;

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        return [
            'greeting' => $this->greeting($user),
            'sections' => array_values(array_filter([
                $this->buyerSection($user),
                $this->sellerSection($user),
                $this->academySection($user),
                $this->consultationSection($user),
                $this->quotationSection($user),
                $this->mentorshipSection($user),
                $this->jobsSection($user),
            ])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function greeting(User $user): array
    {
        $hour = (int) now()->format('G');

        return [
            'name' => $user->displayName(),
            'part' => match (true) {
                $hour < 12 => __('Good morning'),
                $hour < 17 => __('Good afternoon'),
                default => __('Good evening'),
            },
        ];
    }

    /**
     * Things bought. Shown to anybody who has ever placed an order.
     *
     * @return array<string, mixed>|null
     */
    private function buyerSection(User $user): ?array
    {
        $orders = Order::query()
            ->where('user_id', $user->getKey())
            // subOrders.seller because the row names who it came from, and
            // without it this is one query per order on a page that shows three.
            ->with(['subOrders.seller'])
            ->latest()
            ->limit(self::PREVIEW_ROWS)
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        return [
            'key' => 'orders',
            'title' => __('Your orders'),
            'href' => route('orders.index'),
            'linkLabel' => __('All orders'),
            'rows' => $orders->map(fn (Order $order): array => [
                'title' => __('Order :reference', ['reference' => $order->reference]),
                'meta' => trans_choice('{1} 1 seller|[2,*] :count sellers', $order->subOrders->count(), [
                    'count' => $order->subOrders->count(),
                ]),
                'status' => $order->status?->label(),
                'amount' => Money::fromKobo($order->grand_total_kobo ?? 0),
                'href' => route('orders.show', $order),
            ])->all(),
        ];
    }

    /**
     * Things sold. Shown to anybody holding the seller role, empty or not.
     *
     * @return array<string, mixed>|null
     */
    private function sellerSection(User $user): ?array
    {
        $seller = $user->sellerProfile;

        if ($seller === null) {
            return null;
        }

        $subOrders = SubOrder::query()
            ->forSeller($seller)
            ->with(['order.user'])
            ->latest()
            ->limit(self::PREVIEW_ROWS)
            ->get();

        return [
            'key' => 'selling',
            'title' => __('Your sales'),
            'href' => route('filament.seller.pages.earnings'),
            'linkLabel' => __('Seller panel'),
            'empty' => __('No orders yet. They will appear here and in your seller panel.'),
            'rows' => $subOrders->map(fn (SubOrder $subOrder): array => [
                'title' => __('Order :reference', ['reference' => $subOrder->reference]),
                'meta' => $subOrder->order?->user?->displayName(),
                'status' => $subOrder->status?->label(),
                'amount' => Money::fromKobo($subOrder->grandTotalKobo()),
                'href' => route('filament.seller.pages.earnings'),
            ])->all(),
        ];
    }

    /**
     * Courses. Shown to anybody enrolled in one.
     *
     * @return array<string, mixed>|null
     */
    private function academySection(User $user): ?array
    {
        /*
         * Progress is aggregated here rather than through the model's
         * progressPercent(), which is right for a course page and wrong for
         * this one: it fires three queries per enrolment, so three rows on a
         * dashboard became nine queries before anything else had loaded.
         *
         * Same arithmetic — completed lessons over lessons that exist now — in
         * two joins instead.
         */
        $enrolments = Enrolment::query()
            ->ownedBy($user)
            ->with(['course' => fn ($query) => $query->withCount('lessons')])
            ->withCount([
                'progress as completed_lessons_count' => fn ($query) => $query->where('is_completed', true),
            ])
            ->latest()
            ->limit(self::PREVIEW_ROWS)
            ->get();

        if ($enrolments->isEmpty()) {
            return null;
        }

        return [
            'key' => 'academy',
            'title' => __('Your training'),
            'href' => route('academy.mine'),
            'linkLabel' => __('All courses'),
            'rows' => $enrolments->map(function (Enrolment $enrolment): array {
                $total = (int) ($enrolment->course?->lessons_count ?? 0);
                $done = (int) ($enrolment->completed_lessons_count ?? 0);

                return [
                    'title' => $enrolment->course?->title ?? __('A course'),
                    // The single most useful thing on this row: how far in they are.
                    'meta' => $total > 0
                        ? __(':percent% complete', ['percent' => (int) floor(($done / $total) * 100)])
                        : __('Not started'),
                    'status' => $enrolment->completed_at !== null ? __('Finished') : __('In progress'),
                    'href' => route('academy.player', $enrolment->course),
                ];
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function consultationSection(User $user): ?array
    {
        $consultations = Consultation::query()
            ->ownedBy($user)
            ->latest()
            ->limit(self::PREVIEW_ROWS)
            ->get();

        if ($consultations->isEmpty()) {
            return null;
        }

        return [
            'key' => 'consultations',
            'title' => __('Your consultations'),
            'href' => route('consultations.index'),
            'linkLabel' => __('All consultations'),
            'rows' => $consultations->map(fn (Consultation $consultation): array => [
                // No subject column on a consultation — the booking form asks
                // for a category and a description of the situation, and the
                // category is the part that fits on one line.
                'title' => $consultation->category ?: __('Consultation :reference', [
                    'reference' => $consultation->reference,
                ]),
                'meta' => $consultation->tier?->label(),
                'status' => $consultation->status?->label(),
                'href' => route('consultations.show', $consultation),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function quotationSection(User $user): ?array
    {
        $requests = QuotationRequest::query()
            ->ownedBy($user)
            ->with(['currentQuotation'])
            ->latest()
            ->limit(self::PREVIEW_ROWS)
            ->get();

        if ($requests->isEmpty()) {
            return null;
        }

        return [
            'key' => 'quotations',
            'title' => __('Farm setup'),
            'href' => route('quotations.index'),
            'linkLabel' => __('All requests'),
            'rows' => $requests->map(fn (QuotationRequest $request): array => [
                'title' => $request->projectType?->shortLabel() ?? __('Farm setup'),
                'meta' => $request->place(),
                'status' => $request->status?->label(),
                'href' => route('quotations.show', $request),
            ])->all(),
        ];
    }

    /**
     * Mentorship, from whichever side this person is on.
     *
     * @return array<string, mixed>|null
     */
    private function mentorshipSection(User $user): ?array
    {
        $isMentor = $user->mentorProfile !== null;

        $engagements = MentorshipEngagement::query()
            ->when(
                $isMentor,
                fn ($query) => $query->where('mentor_profile_id', $user->mentorProfile->getKey()),
                fn ($query) => $query->ownedBy($user),
            )
            ->with(['mentor.user', 'client', 'package'])
            ->latest()
            ->limit(self::PREVIEW_ROWS)
            ->get();

        if ($engagements->isEmpty() && ! $isMentor) {
            return null;
        }

        return [
            'key' => 'mentorship',
            'title' => $isMentor ? __('People you are mentoring') : __('Your mentorship'),
            'href' => $isMentor
                ? route('filament.mentor.pages.dashboard')
                : route('mentorship.index'),
            'linkLabel' => $isMentor ? __('Mentor panel') : __('All sessions'),
            'empty' => __('No engagements yet.'),
            'rows' => $engagements->map(fn (MentorshipEngagement $engagement): array => [
                // The row names the other party, which is different depending
                // on which side of it you are.
                'title' => $isMentor
                    ? ($engagement->client?->displayName() ?? __('A client'))
                    : ($engagement->mentor?->user?->displayName() ?? __('A mentor')),
                'meta' => $engagement->package?->name,
                'status' => $engagement->status?->label(),
                'href' => route('mentorship.index'),
            ])->all(),
        ];
    }

    /**
     * Job applications, from whichever side this person is on.
     *
     * @return array<string, mixed>|null
     */
    private function jobsSection(User $user): ?array
    {
        $worker = $user->workerProfile;

        if ($worker === null) {
            return null;
        }

        $applications = JobApplication::query()
            ->where('worker_profile_id', $worker->getKey())
            ->with(['listing.employer'])
            ->latest()
            ->limit(self::PREVIEW_ROWS)
            ->get();

        return [
            'key' => 'jobs',
            'title' => __('Your job applications'),
            'href' => route('jobs.worker.dashboard'),
            'linkLabel' => __('All applications'),
            'empty' => __('You have not applied for anything yet.'),
            'rows' => $applications->map(fn (JobApplication $application): array => [
                'title' => $application->listing?->title ?? __('A job'),
                'meta' => $application->listing?->employer?->business_name,
                'status' => $application->status?->label(),
                'href' => route('jobs.worker.dashboard'),
            ])->all(),
        ];
    }
}
