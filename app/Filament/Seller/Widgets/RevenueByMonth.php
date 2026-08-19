<?php

namespace App\Filament\Seller\Widgets;

use App\Enums\LedgerType;
use App\Models\WalletTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Twelve months of sales.
 *
 * Naira rather than kobo on the axis: nobody reads a chart labelled 220000000.
 */
class RevenueByMonth extends ChartWidget
{
    protected ?string $heading = 'Your sales, month by month';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        $byMonth = WalletTransaction::query()
            ->where('user_id', auth()->id())
            ->whereIn('type', [LedgerType::Sale, LedgerType::MentorshipEarning])
            ->where('amount_kobo', '>', 0)
            ->where('created_at', '>=', $start)
            ->get(['amount_kobo', 'created_at'])
            ->groupBy(fn (WalletTransaction $entry): string => $entry->created_at->format('Y-m'))
            ->map(fn ($entries): int => (int) $entries->sum('amount_kobo'));

        $months = collect(range(0, 11))
            ->map(fn (int $offset): Carbon => $start->copy()->addMonths($offset));

        return [
            'datasets' => [
                [
                    'label' => __('Sales (₦)'),
                    'data' => $months
                        ->map(fn (Carbon $month): float => ($byMonth[$month->format('Y-m')] ?? 0) / 100)
                        ->all(),
                    'backgroundColor' => '#0E5138',
                    'borderColor' => '#0E5138',
                ],
            ],
            'labels' => $months->map(fn (Carbon $month): string => $month->format('M y'))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
