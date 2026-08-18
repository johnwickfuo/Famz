<?php

namespace App\Services\Sellers;

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\SellerStatus;
use App\Mail\SellerApprovedMail;
use App\Mail\SellerMoreInfoMail;
use App\Mail\SellerRejectedMail;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The one path through which a seller application changes state.
 *
 * The admin panel, the public form and the tests all come through here, so the
 * rules — who gets the seller role, when the notification goes out, what gets
 * recorded about the decision — exist in exactly one place.
 */
class SellerApplicationService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>  $categoryIds
     */
    public function submit(User $user, array $attributes, array $categoryIds = []): SellerProfile
    {
        return DB::transaction(function () use ($user, $attributes, $categoryIds): SellerProfile {
            // Read fresh: a relation loaded earlier in the request would hide a
            // profile created since, and we would write a second one.
            $seller = SellerProfile::query()->where('user_id', $user->getKey())->first()
                ?? new SellerProfile(['user_id' => $user->getKey()]);

            $seller->fill($attributes);
            $seller->user_id = $user->getKey();
            $seller->status = SellerStatus::Pending;
            $seller->submitted_at = now();

            // A resubmission after "more information needed" clears the old
            // note, so the applicant is not left staring at a request they
            // have already answered.
            $seller->review_notes = null;
            $seller->reviewed_at = null;
            $seller->reviewed_by = null;

            $seller->save();

            $seller->categories()->sync($categoryIds);

            return $seller->refresh();
        });
    }

    /**
     * Approve the application: grant the seller role, open the seller panel,
     * and tell them.
     */
    public function approve(SellerProfile $seller, ?User $reviewer = null): SellerProfile
    {
        DB::transaction(function () use ($seller, $reviewer): void {
            $seller->forceFill([
                'status' => SellerStatus::Approved,
                'review_notes' => null,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer?->getKey(),
            ])->save();

            // The role is what actually opens /seller. Granting it here rather
            // than in the panel means every approval path agrees.
            $seller->user->assignRole(RoleName::Seller->value);
        });

        Mail::to($seller->user->email)->send(new SellerApprovedMail($seller->refresh()));

        return $seller;
    }

    /**
     * Reject with a reason. The reason is required: "no" without a "why" is
     * not something a trader can act on.
     */
    public function reject(SellerProfile $seller, string $reason, ?User $reviewer = null): SellerProfile
    {
        DB::transaction(function () use ($seller, $reason, $reviewer): void {
            $seller->forceFill([
                'status' => SellerStatus::Rejected,
                'review_notes' => $reason,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer?->getKey(),
            ])->save();

            // A rejected seller loses the ability to list, and their live
            // products go with it — see withdrawListings().
            $seller->user->removeRole(RoleName::Seller->value);
            $this->withdrawListings($seller);
        });

        Mail::to($seller->user->email)->send(new SellerRejectedMail($seller->refresh(), $reason));

        return $seller;
    }

    /**
     * Ask for more information, leaving the application open for editing.
     */
    public function requestMoreInformation(SellerProfile $seller, string $notes, ?User $reviewer = null): SellerProfile
    {
        $seller->forceFill([
            'status' => SellerStatus::NeedsMoreInfo,
            'review_notes' => $notes,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer?->getKey(),
        ])->save();

        Mail::to($seller->user->email)->send(new SellerMoreInfoMail($seller->refresh(), $notes));

        return $seller;
    }

    /**
     * Pull a rejected seller's listings out of the catalogue. Their drafts are
     * left alone — the seller may yet reapply, and their work should still be
     * there when they do.
     */
    private function withdrawListings(SellerProfile $seller): void
    {
        $seller->products()
            ->whereIn('status', [
                ProductStatus::Active,
                ProductStatus::OutOfStock,
                ProductStatus::PendingReview,
            ])
            ->update([
                'status' => ProductStatus::Rejected,
                'review_notes' => __('Withdrawn because the seller account is no longer approved.'),
                'published_at' => null,
            ]);
    }
}
