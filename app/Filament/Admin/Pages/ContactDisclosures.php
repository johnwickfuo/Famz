<?php

namespace App\Filament\Admin\Pages;

use App\Models\WorkerProfileView;
use App\Services\Jobs\WorkerContactGuard;
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
 * Who has been looking at workers' phone numbers.
 *
 * A recruiter working through every open worker in a state to build a call
 * list looks exactly like ordinary use from any single request. It only looks
 * like harvesting when the requests are counted together, which is what this
 * page does.
 *
 * The daily allowance already stops the crudest version. This exists for the
 * patterns it does not catch: an account that spends its full allowance every
 * single day, one that opens profiles far outside where it says it farms, and
 * one that looks at hundreds of workers without ever posting a job.
 */
class ContactDisclosures extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'Farm jobs';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.admin.pages.contact-disclosures';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Who saw whom');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Contact disclosures');
    }

    public function getSubheading(): ?string
    {
        return __('Every time a worker\'s phone number was released, and to whom.');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->subDays(30)->toDateString(),
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
            : now()->subDays(30)->startOfDay();

        $until = filled($this->data['until'] ?? null)
            ? Carbon::parse($this->data['until'])->endOfDay()
            : now()->endOfDay();

        $guard = app(WorkerContactGuard::class);
        $limit = $guard->dailyLimit();

        $scope = fn () => WorkerProfileView::query()
            ->whereBetween('created_at', [$from, $until]);

        /*
         * Ranked by distinct workers rather than by raw view count. Somebody
         * refreshing one profile ten times is doing nothing interesting;
         * somebody opening two hundred different people is.
         */
        $topViewers = $scope()
            ->releasing()
            ->whereNotNull('user_id')
            ->select('user_id')
            ->selectRaw('COUNT(DISTINCT worker_profile_id) as workers_seen')
            ->selectRaw('COUNT(*) as total_views')
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) as active_days')
            ->groupBy('user_id')
            ->orderByDesc('workers_seen')
            ->limit(25)
            ->with(['user', 'employer'])
            ->get()
            ->map(function (WorkerProfileView $row) use ($limit): array {
                $perDay = $row->active_days > 0 ? $row->workers_seen / $row->active_days : 0;

                return [
                    'user' => $row->user?->name ?? __('Deleted account'),
                    'email' => $row->user?->email,
                    'employer' => $row->employer?->business_name,
                    'workers_seen' => (int) $row->workers_seen,
                    'total_views' => (int) $row->total_views,
                    'active_days' => (int) $row->active_days,
                    'per_day' => round($perDay, 1),
                    /*
                     * The signal worth acting on: an account averaging close to
                     * its full allowance every day it is active is not filling
                     * one job, whatever it says it is doing.
                     */
                    'at_limit' => $perDay >= ($limit * 0.8),
                ];
            })
            ->all();

        // Workers being looked at far more than anybody else. Usually harmless
        // — a good profile gets opened — but occasionally somebody's number
        // being passed around.
        $mostViewed = $scope()
            ->releasing()
            ->select('worker_profile_id')
            ->selectRaw('COUNT(DISTINCT user_id) as employers')
            ->groupBy('worker_profile_id')
            ->orderByDesc('employers')
            ->limit(10)
            ->with('worker')
            ->get()
            ->map(fn (WorkerProfileView $row): array => [
                'worker' => $row->worker?->full_name ?? __('Deleted profile'),
                'state' => $row->worker?->state,
                'employers' => (int) $row->employers,
            ])
            ->all();

        $releasedCount = (clone $scope())->releasing()->count();
        $totalCount = (clone $scope())->count();

        return [
            'from' => $from,
            'until' => $until,
            'limit' => $limit,
            'totals' => [
                'views' => $totalCount,
                'released' => $releasedCount,
                // Refused views are not failures: most are anonymous visitors
                // landing on a profile, which is the rule working.
                'refused' => $totalCount - $releasedCount,
                'workers' => (clone $scope())->releasing()->distinct()->count('worker_profile_id'),
                'accounts' => (clone $scope())->releasing()->distinct()->count('user_id'),
            ],
            'topViewers' => $topViewers,
            'mostViewed' => $mostViewed,
            'recent' => $scope()
                ->releasing()
                ->with(['user', 'worker', 'employer'])
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (WorkerProfileView $view): array => [
                    'when' => $view->created_at?->format('j M, H:i'),
                    'user' => $view->user?->name ?? __('Deleted account'),
                    'employer' => $view->employer?->business_name,
                    'worker' => $view->worker?->full_name ?? __('Deleted profile'),
                    'ip' => $view->ip_address,
                ])
                ->all(),
        ];
    }
}
