<?php

namespace App\Filament\Admin\Pages;

use App\Services\Reporting\PlatformFinances;
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
 * Do the books agree with the money?
 *
 * Two independent views of the same period: what buyers were charged, taken
 * from the orders, and what the ledger says the platform then owed and owned.
 * They are built from different tables by different code paths, so when they
 * agree it means something.
 *
 * A difference is not automatically a disaster — a payment that landed after
 * the period closed will show one — but it is always worth a look, and this
 * page exists so that looking takes a minute rather than an afternoon.
 */
class Reconciliation extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.admin.pages.reconciliation';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Reconciliation');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Reconciliation');
    }

    public function getSubheading(): ?string
    {
        return __('What buyers were charged, against what the ledger says happened to it.');
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
                    DatePicker::make('from')
                        ->label(__('From'))
                        ->live(),

                    DatePicker::make('until')
                        ->label(__('Until'))
                        ->live(),
                ])->columns(2),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $from = filled($this->data['from'] ?? null) ? Carbon::parse($this->data['from'])->startOfDay() : null;
        $until = filled($this->data['until'] ?? null) ? Carbon::parse($this->data['until'])->endOfDay() : null;

        $figures = app(PlatformFinances::class)->reconciliation($from, $until);

        return [
            'figures' => $figures,
            'money' => fn (int $kobo): string => Money::fromKobo($kobo),
            'balanced' => $figures['difference_kobo'] === 0,
            'rows' => [
                [
                    'label' => __('Charged to buyers'),
                    'help' => trans_choice(
                        'Across :count paid order|Across :count paid orders',
                        $figures['orders'],
                        ['count' => $figures['orders']],
                    ),
                    'value' => Money::fromKobo($figures['collected_kobo']),
                ],
                [
                    'label' => __('Refunded to buyers'),
                    'help' => __('Rejections and dispute refunds.'),
                    'value' => '− '.Money::fromKobo($figures['refunded_kobo']),
                ],
                [
                    'label' => __('Should be accounted for'),
                    'help' => __('Charged less refunded.'),
                    'value' => Money::fromKobo($figures['expected_kobo']),
                    'strong' => true,
                ],
                [
                    'label' => __('The ledger accounts for'),
                    'help' => __('Sellers\' sales and the platform\'s commission, less reversals.'),
                    'value' => Money::fromKobo($figures['net_ledger_kobo']),
                    'strong' => true,
                ],
            ],
        ];
    }
}
