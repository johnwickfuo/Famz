<?php

namespace App\Filament\Seller\Pages;

use App\Filament\Seller\Resources\Products\ProductResource;
use App\Support\Money;
use App\Support\Nigeria;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What this seller charges to deliver to each state.
 *
 * A state with no rate here simply does not offer seller delivery at checkout —
 * an unpriced delivery is a promise nobody has costed, and a buyer finding out
 * afterwards is a refund waiting to happen.
 *
 * @property-read Schema $form
 */
class DeliveryRates extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return __('Delivery rates');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Delivery rates');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Buyers in a state you have not priced can still collect from you — they just will not be offered delivery.');
    }

    public function mount(): void
    {
        $seller = ProductResource::currentSeller();

        abort_if($seller === null, 403);

        $this->form->fill([
            'rates' => $seller->deliveryRates()
                ->orderBy('state')
                ->get()
                ->map(fn ($rate): array => [
                    'state' => $rate->state,
                    'fee_naira' => $rate->fee_kobo / 100,
                    'is_active' => $rate->is_active,
                ])->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('rates')
                    ->label(__('States you deliver to'))
                    ->addActionLabel(__('Add a state'))
                    ->columns(3)
                    ->defaultItems(0)
                    ->schema([
                        Select::make('state')
                            ->label(__('State'))
                            ->options(array_combine(Nigeria::states(), Nigeria::states()))
                            ->searchable()
                            ->required()
                            ->distinct(),

                        TextInput::make('fee_naira')
                            ->label(__('Delivery fee'))
                            ->prefix(Money::SIGN)
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        Toggle::make('is_active')
                            ->label(__('Offering it'))
                            ->default(true),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $seller = ProductResource::currentSeller();

        abort_if($seller === null, 403);

        $rates = collect($this->form->getState()['rates'] ?? []);

        // Anything the seller removed stops being offered.
        $seller->deliveryRates()
            ->whereNotIn('state', $rates->pluck('state')->filter())
            ->delete();

        foreach ($rates as $rate) {
            $seller->deliveryRates()->updateOrCreate(
                ['state' => $rate['state']],
                [
                    'fee_kobo' => Money::toKobo($rate['fee_naira'] ?? 0),
                    'is_active' => (bool) ($rate['is_active'] ?? true),
                ],
            );
        }

        Notification::make()->title(__('Delivery rates saved'))->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Save rates'))->submit('save'),
                    ])->key('form-actions'),
                ]),
        ]);
    }
}
