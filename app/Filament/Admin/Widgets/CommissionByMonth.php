<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\PlatformFinances;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Commission, month by month, in Naira.
 */
class CommissionByMonth extends ChartWidget
{
    protected ?string $heading = 'Commission earned';

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    public ?string $filter = '12';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            '6' => __('Last 6 months'),
            '12' => __('Last 12 months'),
            '24' => __('Last 2 years'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $months = max(1, (int) $this->filter);
        $byMonth = app(PlatformFinances::class)->commissionByMonth($months);

        return [
            'datasets' => [[
                'label' => __('Commission (₦)'),
                'data' => $byMonth->map(fn (int $kobo): float => $kobo / 100)->values()->all(),
                'backgroundColor' => '#F5B711',
                'borderColor' => '#0E5138',
                'borderWidth' => 2,
            ]],
            'labels' => $byMonth->keys()
                ->map(fn (string $key): string => Carbon::createFromFormat('Y-m', $key)->format('M y'))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
