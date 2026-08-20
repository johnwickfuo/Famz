<?php

namespace App\Filament\Admin\Resources\QuotationRequests\RelationManagers;

use App\Documents\QuotationProposalDocument;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationLineItem;
use App\Models\QuotationRequest;
use App\Services\Quotations\QuotationNotifier;
use App\Services\Quotations\QuotationService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

/**
 * The proposal builder.
 *
 * Written by hand, line by line. There is no pricing engine and there is not
 * meant to be one: what it costs to put up a 20,000-bird layer house depends on
 * the site, the season and who is supplying the sheet, and a formula would
 * produce a number nobody in this company could defend on the phone.
 *
 * A sent version is never edited. Revising creates the next version and
 * supersedes the last, so the document a client took to their bank keeps saying
 * exactly what it said.
 */
class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Proposals';

    /**
     * Filament makes relation managers read-only on resource View pages by
     * default. A quotation request has no Edit page — the record is the
     * client's own brief and nobody should be retyping it — so this manager
     * lives on the View page, and without this it would be a proposal builder
     * you cannot build a proposal on.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label(__('Title'))
                ->required()
                ->maxLength(200)
                ->placeholder(__('20,000-bird layer farm — Akinyele, Oyo'))
                ->columnSpanFull(),

            Textarea::make('executive_summary')
                ->label(__('Summary'))
                ->rows(4)
                ->helperText(__('The first thing they read, and often the only thing a bank reads. What you are proposing, in plain words.'))
                ->columnSpanFull(),

            Textarea::make('scope_of_work')
                ->label(__('Scope of work'))
                ->rows(6)
                ->helperText(__('What the company will actually do.'))
                ->columnSpanFull(),

            /*
             * The lines. Sections are free text rather than a picklist: an
             * administrator pricing a fish farm needs headings a poultry enum
             * would never contain, and the proposal is a document rather than a
             * data structure.
             */
            Section::make(__('Costs'))
                ->description(__('Group the lines into sections. They print in the order you put them in.'))
                ->schema([
                    Repeater::make('lineItems')
                        ->label(__('Priced lines'))
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->reorderable()
                        ->collapsible()
                        ->defaultItems(1)
                        ->addActionLabel(__('Add a line'))
                        ->itemLabel(fn (array $state): ?string => collect([
                            $state['section'] ?? null,
                            $state['description'] ?? null,
                        ])->filter()->implode(' · ') ?: null)
                        ->columns(12)
                        ->schema([
                            TextInput::make('section')
                                ->label(__('Section'))
                                ->maxLength(120)
                                ->datalist([
                                    __('Site preparation'),
                                    __('Housing'),
                                    __('Equipment'),
                                    __('Stocking'),
                                    __('Training'),
                                    __('Professional fees'),
                                ])
                                ->placeholder(__('Housing'))
                                ->columnSpan(12),

                            Textarea::make('description')
                                ->label(__('What it is'))
                                ->required()
                                ->rows(2)
                                ->maxLength(500)
                                ->columnSpan(12),

                            TextInput::make('quantity')
                                ->label(__('Qty'))
                                ->numeric()
                                ->default(1)
                                ->minValue(0)
                                ->required()
                                // Live, so the running total below moves as the
                                // administrator types rather than after a save.
                                ->live(onBlur: true)
                                ->columnSpan(3),

                            TextInput::make('unit')
                                ->label(__('Unit'))
                                ->maxLength(40)
                                ->placeholder(__('m², bags, birds'))
                                ->columnSpan(3),

                            TextInput::make('unit_price_naira')
                                ->label(__('Unit price'))
                                ->prefix(Money::SIGN)
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateHydrated(fn (TextInput $component, $state, ?QuotationLineItem $record) => $component->state(
                                    $record ? $record->unit_price_kobo / 100 : ($state ?? 0),
                                ))
                                ->columnSpan(3),

                            Placeholder::make('line_total')
                                ->label(__('Amount'))
                                ->content(fn (Get $get): string => Money::fromKobo(
                                    (int) round(((float) ($get('quantity') ?? 0)) * Money::toKobo($get('unit_price_naira') ?? 0)),
                                ))
                                ->columnSpan(3),
                        ])
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::lineToKobo($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::lineToKobo($data)),

                    TextInput::make('contingency_percent')
                        ->label(__('Contingency'))
                        ->suffix('%')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(100)
                        ->live(onBlur: true)
                        ->helperText(__('A percentage on top for what always goes wrong. Rounded to the naira on the proposal.')),

                    /*
                     * The running total, computed from what is on screen rather
                     * than from what is saved. An administrator pricing a build
                     * needs to know where they are while they type, not after.
                     */
                    Placeholder::make('running_total')
                        ->label(__('Running total'))
                        ->content(function (Get $get): string {
                            $subtotal = collect($get('lineItems') ?? [])
                                ->sum(fn (array $line): int => (int) round(
                                    ((float) ($line['quantity'] ?? 0)) * Money::toKobo($line['unit_price_naira'] ?? 0),
                                ));

                            $contingency = (int) round($subtotal * ((float) ($get('contingency_percent') ?? 0)) / 100 / 100) * 100;

                            return __(':subtotal + :contingency contingency = :total', [
                                'subtotal' => Money::fromKobo((int) $subtotal),
                                'contingency' => Money::fromKobo($contingency),
                                'total' => Money::fromKobo((int) $subtotal + $contingency),
                            ]);
                        }),
                ]),

            Section::make(__('The small print'))
                ->description(__('What stops an argument in month three.'))
                ->collapsed()
                ->schema([
                    Textarea::make('assumptions')
                        ->label(__('What we have assumed'))
                        ->rows(4)
                        ->helperText(__('Access, soil, power, exchange rate, what the client is providing.'))
                        ->columnSpanFull(),

                    Textarea::make('exclusions')
                        ->label(__('What is not included'))
                        ->rows(4)
                        ->helperText(__('Say it here or pay for it later.'))
                        ->columnSpanFull(),

                    Textarea::make('timeline_description')
                        ->label(__('How long it takes'))
                        ->rows(3)
                        ->columnSpanFull(),

                    Textarea::make('payment_terms')
                        ->label(__('Payment terms'))
                        ->rows(3)
                        ->placeholder(__('40% to start, 40% at roofing, 20% on handover.'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')
                    ->label(__('Ver'))
                    ->formatStateUsing(fn (int $state): string => 'v'.$state)
                    ->weight('bold'),

                TextColumn::make('title')
                    ->label(__('Proposal'))
                    ->wrap(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (QuotationStatus $state): string => $state->label())
                    ->color(fn (QuotationStatus $state): string => $state->filamentColour()),

                TextColumn::make('total_kobo')
                    ->label(__('Total'))
                    ->formatStateUsing(fn (int $state): string => Money::fromKobo($state))
                    ->alignRight(),

                TextColumn::make('valid_until')
                    ->label(__('Valid until'))
                    ->date('j M Y')
                    ->placeholder('—')
                    ->visibleFrom('md'),

                TextColumn::make('sent_at')
                    ->label(__('Sent'))
                    ->dateTime('j M Y')
                    ->placeholder(__('Not sent'))
                    ->visibleFrom('lg'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Start a proposal'))
                    // The gate, enforced rather than warned about.
                    ->visible(fn (): bool => $this->ownerRequest()->isWorkable()
                        && ! $this->ownerRequest()->quotations()->where('status', QuotationStatus::Draft)->exists())
                    ->using(function (array $data): Quotation {
                        $request = $this->ownerRequest();

                        $quotation = app(QuotationService::class)->startDraft($request, auth()->user());

                        // startDraft creates the row; the form's own fields and
                        // its line-item repeater are applied on top of it.
                        $quotation->fill($data)->save();

                        return $quotation;
                    })
                    ->after(fn (Quotation $record) => $record->load('lineItems')->recalculate()->save()),
            ])
            ->recordActions([
                Action::make('send')
                    ->label(__('Send it'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->button()
                    ->visible(fn (Quotation $record): bool => $record->isDraft())
                    ->requiresConfirmation()
                    ->modalHeading(__('Send this proposal'))
                    ->modalDescription(fn (): string => __('It is emailed to the client with a PDF, and the price stands for :days days. After this it cannot be edited — only revised.', [
                        'days' => (int) settings('quote_validity_days', 30),
                    ]))
                    ->modalSubmitActionLabel(__('Send it'))
                    ->action(function (Quotation $record): void {
                        try {
                            $sent = app(QuotationService::class)->send($record, auth()->user());
                        } catch (RuntimeException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        app(QuotationNotifier::class)->quotationSent($sent);

                        Notification::make()
                            ->title(__('Sent'))
                            ->body(__('They have the PDF by email and it is on their dashboard.'))
                            ->success()
                            ->send();
                    }),

                ActionGroup::make([
                    EditAction::make()
                        // A sent proposal is immutable. Editing one would change
                        // a document somebody is already holding.
                        ->visible(fn (Quotation $record): bool => $record->isDraft())
                        ->after(fn (Quotation $record) => $record->load('lineItems')->recalculate()->save()),

                    Action::make('revise')
                        ->label(__('Start a revision'))
                        ->icon('heroicon-o-document-duplicate')
                        ->requiresConfirmation()
                        ->modalDescription(__('Copies this version into a new draft you can change. This one stays exactly as the client received it until you send the new one.'))
                        ->visible(fn (Quotation $record): bool => ! $record->isDraft()
                            && ! $this->ownerRequest()->quotations()->where('status', QuotationStatus::Draft)->exists())
                        ->action(function (Quotation $record): void {
                            try {
                                $revision = app(QuotationService::class)->reviseFrom($record, auth()->user());
                            } catch (RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()
                                ->title(__('Version :number started', ['number' => $revision->version]))
                                ->body(__('Edit it, then send it when you are happy.'))
                                ->success()
                                ->send();
                        }),

                    Action::make('download')
                        ->label(__('Download the PDF'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn (Quotation $record): bool => $record->isSent())
                        ->action(fn (Quotation $record) => response()->streamDownload(
                            fn () => print (app(QuotationService::class)->pdfContents($record)),
                            (new QuotationProposalDocument($record))->filename(),
                        )),

                    DeleteAction::make()
                        ->visible(fn (Quotation $record): bool => $record->isDraft()),
                ]),
            ])
            ->emptyStateHeading(fn (): string => $this->ownerRequest()->isWorkable()
                ? __('No proposal yet')
                : __('Waiting on the study fee'))
            ->emptyStateDescription(fn (): string => $this->ownerRequest()->isWorkable()
                ? __('This is what the client paid the study fee for. Price it out and send it.')
                : __('Nothing gets written until the fee clears. That is the whole point of it.'));
    }

    private function ownerRequest(): QuotationRequest
    {
        /** @var QuotationRequest $request */
        $request = $this->getOwnerRecord();

        return $request;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function lineToKobo(array $data): array
    {
        $data['unit_price_kobo'] = Money::toKobo($data['unit_price_naira'] ?? 0);
        unset($data['unit_price_naira']);

        return $data;
    }
}
