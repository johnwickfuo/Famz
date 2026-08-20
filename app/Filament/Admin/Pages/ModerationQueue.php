<?php

namespace App\Filament\Admin\Pages;

use App\Enums\BuyerRequestStatus;
use App\Enums\DisputeStatus;
use App\Enums\ProductStatus;
use App\Enums\RatingStatus;
use App\Enums\SellerStatus;
use App\Models\BuyerRequest;
use App\Models\Dispute;
use App\Models\MentorReview;
use App\Models\Product;
use App\Models\SellerProfile;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Everything waiting on a decision, in one place.
 *
 * Five different things need approving on this platform and each has its own
 * screen. That is right for doing the work and wrong for finding it: an
 * administrator who opens five screens every morning to see whether any of them
 * has anything in it will, on a busy week, stop opening the quiet ones — and
 * the quiet one is where the seller application sat for nine days.
 *
 * Ordered by how much a delay costs somebody else. An open dispute has two
 * people's money frozen and is first. A pending seller application is a
 * business that cannot trade. A pending product is one listing. A review is
 * nobody's livelihood.
 *
 * Counts only, with a link into the screen built for the work. This page is for
 * noticing, not for deciding — approving a seller from a summary row is how a
 * seller gets approved without anybody reading the application.
 */
class ModerationQueue extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Oversight';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.moderation-queue';

    public static function getNavigationLabel(): string
    {
        return __('Waiting on you');
    }

    /**
     * The total, on the navigation item itself.
     *
     * The whole point of the page: an administrator should not have to open it
     * to find out whether it is worth opening.
     */
    public static function getNavigationBadge(): ?string
    {
        $total = array_sum(array_column(self::counts(), 'count'));

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        // Red only when something is actually urgent — a frozen dispute —
        // rather than for any queue at all. A badge that is always red is a
        // badge nobody reads.
        return self::openDisputes() > 0 ? 'danger' : 'warning';
    }

    public function getTitle(): string|Htmlable
    {
        return __('Waiting on you');
    }

    public function getSubheading(): ?string
    {
        return __('Everything across the platform that needs a decision, most costly delay first.');
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $rows = self::counts();

        return [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'count')),
        ];
    }

    private static function openDisputes(): int
    {
        return Dispute::query()->whereIn('status', [DisputeStatus::Open, DisputeStatus::UnderReview])->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function counts(): array
    {
        return [
            [
                'key' => 'disputes',
                'label' => __('Open disputes'),
                'why' => __('Money is frozen for two people until this is settled.'),
                'count' => self::openDisputes(),
                'url' => route('filament.admin.resources.disputes.index'),
                'urgent' => true,
            ],
            [
                'key' => 'sellers',
                'label' => __('Seller applications'),
                'why' => __('A business that cannot trade until somebody reads this.'),
                'count' => SellerProfile::query()
                    ->whereIn('status', [SellerStatus::Pending, SellerStatus::NeedsMoreInfo])
                    ->count(),
                'url' => route('filament.admin.resources.seller-profiles.index'),
                'urgent' => false,
            ],
            [
                'key' => 'products',
                'label' => __('Listings to review'),
                'why' => __('A seller waiting to put something on the market.'),
                'count' => Product::query()->where('status', ProductStatus::PendingReview)->count(),
                'url' => route('filament.admin.resources.products.index'),
                'urgent' => false,
            ],
            [
                'key' => 'requests',
                'label' => __('Buying requests'),
                'why' => __('A buyer waiting for sellers to be able to see what they want.'),
                'count' => BuyerRequest::query()->where('status', BuyerRequestStatus::PendingApproval)->count(),
                'url' => route('filament.admin.resources.buyer-requests.index'),
                'urgent' => false,
            ],
            [
                'key' => 'reviews',
                'label' => __('Mentor reviews'),
                'why' => __('Feedback nobody can see yet.'),
                'count' => MentorReview::query()->where('status', RatingStatus::Pending)->count(),
                'url' => route('filament.admin.resources.mentor-reviews.index'),
                'urgent' => false,
            ],
        ];
    }
}
