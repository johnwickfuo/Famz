<?php

namespace App\Filament\Mentor\Widgets;

use App\Enums\EngagementStatus;
use App\Models\MentorshipEngagement;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The mentor's standing, in four numbers.
 *
 * Held money is shown beside available money rather than folded into it. It is
 * earned and not yet spendable, and a mentor who thinks otherwise will ask for
 * a payout that gets refused.
 */
class MentorOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -20;

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $user = auth()->user();
        $mentor = $user?->mentorProfile;
        $wallet = app(WalletService::class);

        $engagements = MentorshipEngagement::query()
            ->where('mentor_profile_id', $mentor?->getKey() ?? 0);

        $running = (clone $engagements)->where('status', EngagementStatus::Active)->count();
        $waiting = (clone $engagements)->where('status', EngagementStatus::AwaitingConfirmation)->count();

        return [
            Stat::make(__('Ready to withdraw'), Money::fromKobo($wallet->availableBalance($user)))
                ->description(__('Yours to take out now.'))
                ->color('success'),

            Stat::make(__('Held'), Money::fromKobo($wallet->heldBalance($user)))
                ->description($waiting > 0
                    ? trans_choice(
                        'One engagement is waiting on a client to confirm.|:count engagements are waiting on clients to confirm.',
                        $waiting,
                        ['count' => $waiting],
                    )
                    : __('Released when clients confirm the work.'))
                ->color($waiting > 0 ? 'warning' : 'gray'),

            Stat::make(__('Running now'), (string) $running)
                ->description(__('Engagements under way.'))
                ->color('gray'),

            Stat::make(__('Rating'), $mentor?->average_rating === null ? '—' : $mentor->average_rating.'/5')
                ->description(trans_choice(
                    'No reviews yet.|One review.|:count reviews.',
                    $mentor?->reviews_count ?? 0,
                    ['count' => $mentor?->reviews_count ?? 0],
                ))
                ->color('gray'),
        ];
    }
}
