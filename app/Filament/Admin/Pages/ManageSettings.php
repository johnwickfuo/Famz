<?php

namespace App\Filament\Admin\Pages;

use App\Enums\PayoutMode;
use App\Services\Branding\BrandingKey;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Settings\SettingsService;
use App\Services\Settlement\EscrowDriver;
use App\Services\Settlement\SettlementManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Where the platform is named.
 *
 * Everything on the Company & branding tab feeds BrandingService, which is the
 * only thing in the application that knows what the company is called. Saving
 * here drops the branding cache, so a new name or logo shows up across the
 * site, emails, PDFs and certificates on the very next request — no code change
 * and no redeploy.
 *
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 90;

    /**
     * @var array<int, string>
     */
    private const PLATFORM_KEYS = [
        'marketplace_commission_percent',
        'consultation_standard_response_hours',
        'consultation_urgent_response_hours',
        'consultation_working_hours_start',
        'consultation_working_hours_end',
        'consultation_working_days',
        'consultation_followup_days',
        'buyer_request_expiry_days',
        'quote_validity_days',
        'settlement_driver',
        'active_payment_gateway',
        'escrow_auto_release_days',
        'payout_mode',
        'minimum_withdrawal_amount',
        'payout_schedule_day',
        'dispute_window_days',
        'delivery_quotes_enabled',
    ];

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Settings');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('The company name and logo set here are used everywhere: the site, emails, PDFs and certificates.');
    }

    public function mount(): void
    {
        $settings = app(SettingsService::class);

        $state = [];

        foreach (BrandingKey::cases() as $key) {
            $state[$key->value] = $key === BrandingKey::SocialLinks
                ? ($settings->array($key->value, []) ?? [])
                : $settings->string($key->value);
        }

        foreach (self::PLATFORM_KEYS as $key) {
            $state[$key] = $settings->get($key);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->tabs([
                        Tab::make(__('Company & branding'))
                            ->icon('heroicon-o-identification')
                            ->schema($this->brandingFields()),

                        Tab::make(__('Platform rules'))
                            ->icon('heroicon-o-scale')
                            ->schema($this->platformFields()),

                        Tab::make(__('Money'))
                            ->icon('heroicon-o-banknotes')
                            ->schema($this->moneyFields()),
                    ])
                    ->persistTabInQueryString(),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Component>
     */
    private function brandingFields(): array
    {
        $directory = (string) config('branding.directory', 'branding');
        $disk = (string) config('branding.disk', 'public');

        return [
            TextInput::make(BrandingKey::Name->value)
                ->label(__('Company name'))
                ->maxLength(120)
                ->helperText(__('The whole platform follows this one field — the site, emails, PDFs and certificates. Leave it empty and the placeholder name from the server configuration is used instead.'))
                ->columnSpanFull(),

            TextInput::make(BrandingKey::ShortName->value)
                ->label(__('Short name'))
                ->maxLength(40)
                ->helperText(__('Used where the full name will not fit — the mobile header, an SMS, a footer.')),

            TextInput::make(BrandingKey::Tagline->value)
                ->label(__('Tagline'))
                ->maxLength(160),

            TextInput::make(BrandingKey::Email->value)
                ->label(__('Contact email'))
                ->email()
                ->maxLength(160),

            TextInput::make(BrandingKey::Phone->value)
                ->label(__('Phone'))
                ->tel()
                ->maxLength(32),

            TextInput::make(BrandingKey::Whatsapp->value)
                ->label(__('WhatsApp'))
                ->tel()
                ->maxLength(32),

            TextInput::make(BrandingKey::RcNumber->value)
                ->label(__('RC number'))
                ->maxLength(32),

            Textarea::make(BrandingKey::Address->value)
                ->label(__('Registered address'))
                ->rows(3)
                ->maxLength(400)
                ->columnSpanFull(),

            FileUpload::make(BrandingKey::Logo->value)
                ->label(__('Logo'))
                ->image()
                ->disk($disk)
                ->directory($directory)
                ->visibility('public')
                ->maxSize(2048)
                ->helperText(__('Optional. Without a logo the company name is set as a wordmark instead.')),

            FileUpload::make(BrandingKey::LogoDark->value)
                ->label(__('Logo for dark backgrounds'))
                ->image()
                ->disk($disk)
                ->directory($directory)
                ->visibility('public')
                ->maxSize(2048)
                ->helperText(__('Optional. Falls back to the main logo.')),

            FileUpload::make(BrandingKey::Favicon->value)
                ->label(__('Favicon'))
                ->image()
                ->disk($disk)
                ->directory($directory)
                ->visibility('public')
                ->maxSize(512),

            KeyValue::make(BrandingKey::SocialLinks->value)
                ->label(__('Social links'))
                ->keyLabel(__('Platform'))
                ->valueLabel(__('URL'))
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function platformFields(): array
    {
        return [
            TextInput::make('marketplace_commission_percent')
                ->label(__('Marketplace commission'))
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.1)
                ->suffix('%')
                ->required(),

            TextInput::make('consultation_standard_response_hours')
                ->label(__('Standard consultation response'))
                ->numeric()
                ->minValue(1)
                ->suffix(__('hours'))
                ->required(),

            TextInput::make('consultation_urgent_response_hours')
                ->label(__('Urgent consultation response'))
                ->numeric()
                ->minValue(1)
                ->suffix(__('hours'))
                ->required(),

            TextInput::make('consultation_working_hours_start')
                ->label(__('Office opens'))
                ->numeric()
                ->minValue(0)
                ->maxValue(23)
                ->suffix(__(':00'))
                // The urgent promise is quoted in WORKING hours, so this is
                // what stops an overnight booking being late before anybody
                // has read it.
                ->helperText(__('The urgent response clock only runs between these hours.'))
                ->required(),

            TextInput::make('consultation_working_hours_end')
                ->label(__('Office closes'))
                ->numeric()
                ->minValue(1)
                ->maxValue(24)
                ->suffix(__(':00'))
                ->required(),

            TextInput::make('consultation_working_days')
                ->label(__('Working days'))
                ->helperText(__('Day numbers, comma separated. 1 is Monday, 7 is Sunday.'))
                ->placeholder('1,2,3,4,5,6')
                ->required(),

            TextInput::make('consultation_followup_days')
                ->label(__('Follow-up window'))
                ->numeric()
                ->minValue(0)
                ->suffix(__('days'))
                ->helperText(__('How long a client can keep asking questions after a consultation is finished.'))
                ->required(),

            TextInput::make('buyer_request_expiry_days')
                ->label(__('Buyer request expiry'))
                ->numeric()
                ->minValue(1)
                ->suffix(__('days'))
                ->required(),

            TextInput::make('quote_validity_days')
                ->label(__('Quote validity'))
                ->numeric()
                ->minValue(1)
                ->suffix(__('days'))
                ->required(),

            Toggle::make('delivery_quotes_enabled')
                ->label(__('Let sellers quote for awkward deliveries'))
                ->helperText(__('Off until the quote conversation is built. Half a negotiation is worse than none.'))
                ->columnSpanFull(),
        ];
    }

    /**
     * Settlement, payouts and disputes: everything that decides when money
     * moves and who has to press a button first.
     *
     * @return array<int, Component>
     */
    private function moneyFields(): array
    {
        return [
            Select::make('active_payment_gateway')
                ->label(__('Payment gateway'))
                ->options(PaymentGatewayManager::options())
                ->helperText(__('Whichever you choose needs its keys in the server configuration.'))
                ->required(),

            Select::make('settlement_driver')
                ->label(__('When sellers are paid'))
                // From the manager, so this list can never offer a driver the
                // application does not have.
                ->options(SettlementManager::options())
                ->live()
                ->required(),

            TextInput::make('escrow_auto_release_days')
                ->label(__('Escrow window'))
                ->numeric()
                ->minValue(1)
                ->maxValue(90)
                ->suffix(__('days'))
                ->helperText(__('After the seller marks a delivery, how long before the money releases on its own.'))
                ->visible(fn ($get): bool => $get('settlement_driver') === EscrowDriver::KEY)
                ->required(),

            TextInput::make('dispute_window_days')
                ->label(__('Time to report a problem'))
                ->numeric()
                ->minValue(1)
                ->maxValue(90)
                ->suffix(__('days'))
                // Shorter than the escrow window and a buyer loses the right to
                // complain before the money has even gone.
                ->helperText(__('Counted from delivery. Keep it at least as long as the escrow window.'))
                ->required(),

            // A radio rather than a select: this decides whether money leaves
            // the platform without anybody looking at it, so both options are
            // on screen with their consequences beside them.
            Radio::make('payout_mode')
                ->label(__('How sellers get paid out'))
                ->options(PayoutMode::options())
                ->descriptions(collect(PayoutMode::cases())
                    ->mapWithKeys(fn (PayoutMode $mode): array => [$mode->value => $mode->description()])
                    ->all())
                ->live()
                ->required()
                ->columnSpanFull(),

            TextInput::make('minimum_withdrawal_amount')
                ->label(__('Smallest payout'))
                ->numeric()
                ->minValue(0)
                ->prefix('₦')
                ->helperText(__('In kobo, as everything is. Below this the transfer fee eats the payout.'))
                ->required(),

            TextInput::make('payout_schedule_day')
                ->label(__('Payout day'))
                ->numeric()
                ->minValue(1)
                ->maxValue(31)
                ->helperText(__('Day of the month. 29 to 31 lands on the last day of a short month.'))
                ->visible(fn ($get): bool => $get('payout_mode') === PayoutMode::ScheduledAuto->value)
                ->required(),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(SettingsService::class);

        $branding = [];

        foreach (BrandingKey::cases() as $key) {
            $value = $state[$key->value] ?? null;

            // Filament's file upload hands back a keyed array of stored paths.
            if (in_array($key, [BrandingKey::Logo, BrandingKey::LogoDark, BrandingKey::Favicon], true)) {
                $value = is_array($value) ? (array_values($value)[0] ?? null) : $value;
            }

            if ($key === BrandingKey::SocialLinks) {
                $value = array_filter(is_array($value) ? $value : [], fn ($url): bool => filled($url));
            }

            $branding[$key->value] = $value;
        }

        $settings->setMany($branding, BrandingKey::GROUP);

        $settings->setMany(
            collect(self::PLATFORM_KEYS)
                ->mapWithKeys(fn (string $key): array => [$key => $state[$key] ?? null])
                ->all(),
            'platform',
        );

        // setMany() has already flushed the settings cache and raised
        // SettingsChanged, which drops the branding cache — so the new name and
        // logo are live from the next request onwards.
        Notification::make()
            ->title(__('Settings saved'))
            ->body(__('Branding is live across the site, emails and documents.'))
            ->success()
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('Save settings'))
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }
}
