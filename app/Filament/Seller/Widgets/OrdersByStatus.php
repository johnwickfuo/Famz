<?php

namespace App\Filament\Seller\Widgets;

use App\Enums\SubOrderStatus;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\SubOrder;
use Filament\Widgets\ChartWidget;

/**
 * Where this seller's orders currently stand.
 *
 * Statuses nobody has any of are dropped rather than drawn as slivers of
 * nothing — a chart of eight zeroes tells a seller less than a chart of two.
 */
class OrdersByStatus extends ChartWidget
{
    protected ?string $heading = 'Your orders';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $counts = SubOrder::query()
            ->forSeller(ProductResource::currentSeller())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $present = collect(SubOrderStatus::cases())
            ->filter(fn (SubOrderStatus $status): bool => ($counts[$status->value] ?? 0) > 0);

        return [
            'datasets' => [[
                'label' => __('Orders'),
                'data' => $present->map(fn (SubOrderStatus $s): int => (int) $counts[$s->value])->values()->all(),
                'backgroundColor' => $present->map(fn (SubOrderStatus $s): string => match ($s) {
                    SubOrderStatus::Pending => '#F5B711',
                    SubOrderStatus::Accepted, SubOrderStatus::Shipped => '#4B7BA8',
                    SubOrderStatus::Delivered, SubOrderStatus::Settled => '#0E5138',
                    SubOrderStatus::Rejected, SubOrderStatus::Disputed, SubOrderStatus::Refunded => '#C22A1B',
                    default => '#9AA292',
                })->values()->all(),
                'borderWidth' => 2,
                'borderColor' => '#ECEFE8',
            ]],
            'labels' => $present->map(fn (SubOrderStatus $s): string => $s->label())->values()->all(),
        ];
    }

    public function isEmpty(): bool
    {
        return SubOrder::query()->forSeller(ProductResource::currentSeller())->doesntExist();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']]];
    }
}
