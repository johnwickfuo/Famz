<?php

namespace App\Services\Mentorship;

use App\Enums\EngagementStatus;
use App\Enums\ReviewStatus;
use App\Models\MentorReview;
use App\Models\MentorshipEngagement;
use App\Models\User;
use RuntimeException;

/**
 * What a client thought, once they are entitled to an opinion.
 *
 * Two gates, and both are load-bearing.
 *
 * The engagement must be COMPLETED — not paid for, not under way. A review is a
 * verdict on work that finished, and letting somebody rate a mentor on day one
 * turns the rating into a measure of how the sales conversation went.
 *
 * And nothing is visible until an administrator approves it. That costs the
 * platform immediacy and buys it the ability to take down a review written in a
 * temper, which on a market where one bad rating can end a small practice is a
 * trade worth making. The mentor can see it while it waits, so nobody is
 * blindsided by a rating that appears with no warning.
 */
class ReviewService
{
    /**
     * Whether this person may review this engagement.
     */
    public function canReview(MentorshipEngagement $engagement, ?User $user): bool
    {
        if ($user === null || $engagement->client_id !== $user->getKey()) {
            return false;
        }

        if ($engagement->status !== EngagementStatus::Completed) {
            return false;
        }

        // One per engagement — enforced by a unique index as well, because two
        // reviews on one piece of work is a rating anybody could inflate.
        return $engagement->review === null;
    }

    /**
     * Why they cannot, in words the page can print.
     */
    public function refusalReason(MentorshipEngagement $engagement, ?User $user): ?string
    {
        return match (true) {
            $user === null || $engagement->client_id !== $user->getKey() => __('Only the client can review this engagement.'),
            $engagement->review !== null => __('You have already reviewed this engagement.'),
            $engagement->status !== EngagementStatus::Completed => __('You can leave a review once the engagement is finished.'),
            default => null,
        };
    }

    public function leave(MentorshipEngagement $engagement, User $client, int $rating, ?string $comment): MentorReview
    {
        if (! $this->canReview($engagement, $client)) {
            throw new RuntimeException(
                $this->refusalReason($engagement, $client) ?? __('This engagement cannot be reviewed.')
            );
        }

        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException(__('Give a rating between one and five.'));
        }

        $review = new MentorReview;

        $review->forceFill([
            'mentorship_engagement_id' => $engagement->getKey(),
            'mentor_profile_id' => $engagement->mentor_profile_id,
            'client_id' => $client->getKey(),
            'rating' => $rating,
            'comment' => trim((string) $comment) ?: null,
            'status' => ReviewStatus::Pending,
        ])->save();

        return $review->refresh();
    }

    public function approve(MentorReview $review, User $admin, ?string $note = null): MentorReview
    {
        $review->forceFill([
            'status' => ReviewStatus::Approved,
            'moderated_by' => $admin->getKey(),
            'moderated_at' => now(),
            'moderation_note' => $note,
        ])->save();

        // The model's saved hook recomputes the mentor's rating from the
        // approved rows, so this is the moment the number changes.
        return $review->refresh();
    }

    public function reject(MentorReview $review, User $admin, string $note): MentorReview
    {
        if (trim($note) === '') {
            // A rejection with no reason is indistinguishable from censorship,
            // and the mentor and client both get to read this.
            throw new RuntimeException(__('Say why it is not being published.'));
        }

        $review->forceFill([
            'status' => ReviewStatus::Rejected,
            'moderated_by' => $admin->getKey(),
            'moderated_at' => now(),
            'moderation_note' => trim($note),
        ])->save();

        return $review->refresh();
    }
}
