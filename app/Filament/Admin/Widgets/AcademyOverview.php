<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The academy in four numbers.
 *
 * Completions are shown as a share of enrolments rather than as a count: two
 * hundred certificates means nothing without knowing whether two hundred and
 * ten people started or two thousand did, and the second is the number worth
 * doing something about.
 */
class AcademyOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $published = Course::query()->where('status', CourseStatus::Published)->count();
        $drafts = Course::query()->where('status', CourseStatus::Draft)->count();

        $enrolments = Enrolment::query()->whereNotNull('enrolled_at');

        $students = (clone $enrolments)->count();
        $finished = (clone $enrolments)->whereNotNull('completed_at')->count();
        $revenue = (int) (clone $enrolments)->sum('price_paid_kobo');

        $thisMonth = (int) (clone $enrolments)
            ->where('enrolled_at', '>=', now()->startOfMonth())
            ->sum('price_paid_kobo');

        return [
            Stat::make(__('Courses published'), (string) $published)
                ->description(trans_choice(
                    'No drafts waiting.|One draft still unpublished.|:count drafts still unpublished.',
                    $drafts,
                    ['count' => $drafts],
                ))
                ->color($drafts > 0 ? 'warning' : 'gray'),

            Stat::make(__('Enrolments'), (string) $students)
                ->description(__('People who have paid for a course.'))
                ->color('gray'),

            Stat::make(__('Finished'), $students > 0 ? round($finished / $students * 100).'%' : '—')
                ->description(trans_choice(
                    'Nobody has finished a course yet.|One person has finished a course.|:count people have finished a course.',
                    $finished,
                    ['count' => $finished],
                ))
                ->color($students > 0 && $finished / $students >= 0.4 ? 'success' : 'warning'),

            // The whole amount, because a course has no seller to split with.
            Stat::make(__('Course income'), Money::fromKobo($revenue))
                ->description(__(':amount of it this month.', ['amount' => Money::fromKobo($thisMonth)]))
                ->color('success'),
        ];
    }
}
