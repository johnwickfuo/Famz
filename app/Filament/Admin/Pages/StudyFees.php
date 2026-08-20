<?php

namespace App\Filament\Admin\Pages;

use App\Enums\StudyFeeCreditStatus;
use App\Filament\Admin\Resources\QuotationRequests\QuotationRequestResource;
use App\Models\QuotationStudyFee;
use App\Services\Quotations\StudyFeeService;
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
 * Study fees collected against study fees credited.
 *
 * The question this page answers is not "how much did we make" — it is "how
 * much of what we charged for studies came back off a project invoice". That
 * ratio is the honest measure of whether the study fee is a revenue line or a
 * deposit, and the company cannot price it sensibly without knowing.
 *
 * The second thing it answers is which fees nobody has decided about. A paid
 * fee with no credit decision recorded is the row that becomes an argument in
 * six months, and this page is where they are visible before that happens.
 */
class StudyFees extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Farm setup';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.pages.study-fees';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Study fees');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Study fees');
    }

    public function getSubheading(): ?string
    {
        return __('What was charged for costing work, and what came back off a project.');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfYear()->toDateString(),
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
        $from = filled($this->data['from'] ?? null) ? Carbon::parse($this->data['from'])->startOfDay() : null;
        $until = filled($this->data['until'] ?? null) ? Carbon::parse($this->data['until'])->endOfDay() : null;

        $tally = app(StudyFeeService::class)->tally($from, $until);

        /*
         * Fees nobody has decided about. Not a status — `uncredited` with a
         * timestamp is a decision, `uncredited` without one is an oversight,
         * and only the second belongs on a list of things to chase.
         */
        $undecided = QuotationStudyFee::query()
            ->paid()
            ->whereNull('credited_at')
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('paid_at', '<=', $until))
            ->with('request.user')
            ->orderBy('paid_at')
            ->limit(20)
            ->get();

        $collected = $tally['collected_kobo'];

        return [
            'tally' => $tally,
            'money' => fn (int $kobo): string => Money::fromKobo($kobo),

            // Guarded, because a company with no study fees yet should see a
            // dash rather than a division by zero.
            'credited_share' => $collected > 0
                ? round($tally['credited_kobo'] / $collected * 100)
                : null,

            'rows' => [
                [
                    'label' => __('Collected'),
                    'help' => trans_choice(
                        'From :count paid study|From :count paid studies',
                        $tally['collected_count'],
                        ['count' => $tally['collected_count']],
                    ),
                    'value' => Money::fromKobo($tally['collected_kobo']),
                    'strong' => true,
                ],
                [
                    'label' => __('Credited to a project'),
                    'help' => __('The client signed and the fee came off their first invoice.'),
                    'value' => Money::fromKobo($tally['credited_kobo']),
                    'tone' => 'success',
                ],
                [
                    'label' => __('Not credited'),
                    'help' => __('Paid for the study, and the study is what it bought.'),
                    'value' => Money::fromKobo($tally['uncredited_kobo']),
                ],
                [
                    'label' => __('Refunded'),
                    'help' => __('Given back. This is the only line that actually left the business.'),
                    'value' => '− '.Money::fromKobo($tally['refunded_kobo']),
                    'tone' => 'danger',
                ],
                [
                    'label' => __('Kept'),
                    'help' => __('Collected less refunded. A credited fee was still collected — it was discounted against an invoice raised elsewhere.'),
                    'value' => Money::fromKobo($tally['retained_kobo']),
                    'strong' => true,
                ],
            ],

            'undecided' => $undecided->map(fn (QuotationStudyFee $fee): array => [
                'reference' => $fee->request?->reference ?? '—',
                'client' => $fee->request?->user?->name ?? '—',
                'amount' => $fee->amount(),
                'paid_at' => $fee->paid_at?->format('j M Y'),
                'waiting' => $fee->paid_at?->diffForHumans(syntax: true),
                'url' => $fee->request === null
                    ? null
                    : QuotationRequestResource::getUrl('view', [
                        'record' => $fee->request,
                    ]),
            ])->all(),

            'statuses' => collect(StudyFeeCreditStatus::cases())
                ->mapWithKeys(fn (StudyFeeCreditStatus $case): array => [$case->value => $case->label()])
                ->all(),
        ];
    }
}
