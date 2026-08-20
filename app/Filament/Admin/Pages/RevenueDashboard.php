<?php

namespace App\Filament\Admin\Pages;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\Enrolment;
use App\Models\JobListing;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\RevenueByStream;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * What the platform earned, where it came from, and whether it is growing.
 *
 * Split by stream rather than totalled, because the five streams behave nothing
 * alike: marketplace commission tracks somebody else's trade, courses are the
 * platform's own product at near-pure margin, and consultations and farm setups
 * are the company's own hours and cannot scale past them. One combined figure
 * hides which of those had a good month.
 */
class RevenueDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Oversight';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.pages.revenue-dashboard';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Resolved per call rather than injected into a property.
     *
     * A Filament page is a Livewire component: it is re-instantiated and
     * hydrated on every request, and a typed property holding a service is
     * state Livewire will try to serialise.
     */
    private function revenue(): RevenueByStream
    {
        return app(RevenueByStream::class);
    }

    public static function getNavigationLabel(): string
    {
        return __('Revenue');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Revenue and activity');
    }

    public function getSubheading(): ?string
    {
        return __('Earnings by stream, how the platform is growing, and what each module is doing.');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'until' => now()->endOfDay()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Form::make([
                    DatePicker::make('from')->label(__('From'))->live(),
                    DatePicker::make('until')->label(__('Until'))->live(),
                ])->columns(2),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $from = filled($this->data['from'] ?? null)
            ? Carbon::parse($this->data['from'])->startOfDay()
            : now()->startOfMonth();

        $until = filled($this->data['until'] ?? null)
            ? Carbon::parse($this->data['until'])->endOfDay()
            : now()->endOfDay();

        $streams = $this->revenue()->between($from, $until);
        $months = $this->revenue()->byMonth(12);

        return [
            'from' => $from,
            'until' => $until,
            'streams' => collect($streams)
                ->map(fn (array $stream): array => [...$stream, 'money' => Money::fromKobo($stream['kobo'])])
                ->all(),
            'total' => Money::fromKobo(array_sum(array_column($streams, 'kobo'))),
            'months' => $months,
            // The tallest month, so the bars have something to scale against.
            // Guarded: a platform with no revenue yet must not divide by zero
            // on its own dashboard.
            'peak' => max(1, max(array_column($months, 'total') ?: [1])),
            'growth' => $this->revenue()->userGrowth(12),
            'activity' => $this->activity($from, $until),
        ];
    }

    /**
     * What each module actually did in the period.
     *
     * Counts rather than money: this answers "is anybody using it", which for
     * the free modules is the only question there is.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(Carbon $from, Carbon $until): array
    {
        $between = fn ($query) => $query->whereBetween('created_at', [$from, $until]);

        return [
            [
                'label' => __('Orders placed'),
                'count' => Order::query()->tap($between)->count(),
            ],
            [
                'label' => __('Listings added'),
                'count' => Product::query()->tap($between)->count(),
            ],
            [
                'label' => __('Course enrolments'),
                'count' => Enrolment::query()->tap($between)->count(),
            ],
            [
                'label' => __('Consultations booked'),
                'count' => Consultation::query()->tap($between)->count(),
            ],
            [
                'label' => __('Consultations answered'),
                'count' => Consultation::query()
                    ->tap($between)
                    ->where('status', ConsultationStatus::Completed)
                    ->count(),
            ],
            [
                'label' => __('Jobs posted'),
                'count' => JobListing::query()->tap($between)->count(),
            ],
            [
                'label' => __('New accounts'),
                'count' => User::query()->tap($between)->count(),
            ],
        ];
    }
}
