<?php

namespace App\Filament\Admin\Resources\QuotationRequests\Pages;

use App\Enums\QuotationScope;
use App\Enums\StudyFeeCreditStatus;
use App\Filament\Admin\Resources\QuotationRequests\QuotationRequestResource;
use App\Models\QuotationRequest;
use App\Services\Quotations\StudyFeeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use RuntimeException;

class ViewQuotationRequest extends ViewRecord
{
    protected static string $resource = QuotationRequestResource::class;

    /**
     * The study-fee credit control sits in the header, not buried in a table.
     *
     * This is the single action on this screen whose absence causes an argument
     * six months later, so it is the one action somebody cannot miss.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordCredit')
                ->label(fn (QuotationRequest $record): string => $record->studyFee?->isDecided()
                    ? __('Change the fee decision')
                    : __('Record the fee decision'))
                ->icon('heroicon-o-receipt-percent')
                ->color(fn (QuotationRequest $record): string => $record->studyFee?->isDecided() ? 'gray' : 'warning')
                ->button()
                ->visible(fn (QuotationRequest $record): bool => $record->studyFeePaid())
                ->modalHeading(__('What happened to the study fee'))
                ->modalDescription(__('This is the record if anybody asks about it later. Your name and the time go on it.'))
                ->modalSubmitActionLabel(__('Record it'))
                ->fillForm(fn (QuotationRequest $record): array => [
                    'credit_status' => $record->studyFee?->credit_status?->value,
                    'credit_note' => $record->studyFee?->credit_note,
                ])
                ->schema([
                    Radio::make('credit_status')
                        ->label(__('Decision'))
                        ->options(StudyFeeCreditStatus::options())
                        ->descriptions(collect(StudyFeeCreditStatus::cases())
                            ->mapWithKeys(fn (StudyFeeCreditStatus $case): array => [$case->value => $case->hint()])
                            ->all())
                        ->required()
                        ->default(StudyFeeCreditStatus::Uncredited->value),

                    Textarea::make('credit_note')
                        ->label(__('Why'))
                        ->rows(3)
                        ->maxLength(1000)
                        // Required by the service for anything but "uncredited",
                        // which needs no explanation: it is simply what was paid
                        // for. The helper says so rather than the field lying
                        // about being optional.
                        ->helperText(__('Needed for anything other than "not credited" — which needs no explanation, since that is what the fee was for.')),
                ])
                ->action(function (QuotationRequest $record, array $data): void {
                    $fee = $record->studyFee;

                    if ($fee === null) {
                        Notification::make()->title(__('There is no study fee on this request.'))->danger()->send();

                        return;
                    }

                    try {
                        app(StudyFeeService::class)->recordCredit(
                            $fee,
                            StudyFeeCreditStatus::from($data['credit_status']),
                            auth()->user(),
                            $data['credit_note'] ?? null,
                        );
                    } catch (RuntimeException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Recorded'))->success()->send();
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * The fee first, above everything else the request contains.
             *
             * Everything below this section is context for writing a proposal;
             * this section is the answer to "should anybody be writing one at
             * all", and to the question somebody will ask about this money next
             * year.
             */
            Section::make(__('The study fee'))
                ->columns(3)
                ->schema([
                    TextEntry::make('studyFee.amount_kobo')
                        ->label(__('Amount'))
                        ->state(fn (QuotationRequest $record): string => $record->studyFee?->amount() ?? '—'),

                    TextEntry::make('studyFee.paid_at')
                        ->label(__('Paid'))
                        ->dateTime('j M Y, H:i')
                        ->placeholder(__('Not paid — this is not work yet'))
                        ->color(fn (QuotationRequest $record): string => $record->studyFeePaid() ? 'success' : 'danger'),

                    /*
                     * Short label in the badge, whole sentence underneath. The
                     * long form does not fit a third of a row and a clipped
                     * badge is worse than a terse one — but the sentence is the
                     * part that actually settles an argument, so it stays.
                     */
                    TextEntry::make('studyFee.credit_status')
                        ->label(__('Credit status'))
                        ->badge()
                        ->placeholder('—')
                        ->formatStateUsing(fn (StudyFeeCreditStatus $state): string => $state->shortLabel())
                        ->color(fn (StudyFeeCreditStatus $state): string => $state->filamentColour())
                        ->helperText(fn (QuotationRequest $record): ?string => $record->studyFee?->isDecided()
                            ? $record->studyFee->credit_status->hint()
                            : __('Nobody has decided yet.')),

                    TextEntry::make('studyFee.creditor.name')
                        ->label(__('Decided by'))
                        ->placeholder('—'),

                    TextEntry::make('studyFee.credited_at')
                        ->label(__('Decided'))
                        ->dateTime('j M Y, H:i')
                        ->placeholder('—'),

                    TextEntry::make('studyFee.payment_reference')
                        ->label(__('Payment'))
                        ->placeholder('—')
                        ->copyable(),

                    TextEntry::make('studyFee.credit_note')
                        ->label(__('Note'))
                        ->placeholder(__('Nothing recorded'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Who is asking'))
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')->label(__('Name')),
                    TextEntry::make('user.email')->label(__('Email'))->copyable(),
                    TextEntry::make('user.profile.phone')->label(__('Phone'))->placeholder('—')->copyable(),
                    TextEntry::make('created_at')->label(__('Asked'))->dateTime('j M Y, H:i'),
                ]),

            Section::make(__('The project'))
                ->columns(3)
                ->schema([
                    TextEntry::make('project_type')
                        ->label(__('Type'))
                        ->badge()
                        ->state(fn (QuotationRequest $record): string => $record->project_type->shortLabel())
                        ->helperText(fn (QuotationRequest $record): string => $record->project_type->hint()),

                    TextEntry::make('farm_type')->label(__('Farm')),

                    TextEntry::make('target_capacity')
                        ->label(__('Target size'))
                        ->state(fn (QuotationRequest $record): string => $record->capacity() ?? '—'),

                    TextEntry::make('scope_wanted')
                        ->label(__('Scope wanted'))
                        ->state(fn (QuotationRequest $record): string => implode(' · ', QuotationScope::labelsFor($record->scope_wanted)) ?: '—')
                        ->columnSpanFull(),

                    TextEntry::make('budget')
                        ->label(__('Budget'))
                        ->state(fn (QuotationRequest $record): string => $record->budgetRange() ?? __('Not stated')),

                    TextEntry::make('target_start_date')
                        ->label(__('Wants to start'))
                        ->date('j M Y')
                        ->placeholder('—'),
                ]),

            Section::make(__('The site'))
                ->columns(3)
                ->schema([
                    TextEntry::make('owns_land')
                        ->label(__('Land'))
                        ->badge()
                        ->state(fn (QuotationRequest $record): string => $record->owns_land
                            ? __('Client owns it')
                            : __('Not secured yet'))
                        ->color(fn (QuotationRequest $record): string => $record->owns_land ? 'success' : 'warning'),

                    TextEntry::make('land_size')
                        ->label(__('Size'))
                        ->state(fn (QuotationRequest $record): string => $record->landSize() ?? '—'),

                    TextEntry::make('place')
                        ->label(__('Where'))
                        ->state(fn (QuotationRequest $record): string => $record->place() ?? '—'),

                    TextEntry::make('power_situation')
                        ->label(__('Power'))
                        ->state(fn (QuotationRequest $record): string => $record->power_situation?->label() ?? '—'),

                    TextEntry::make('water_source')
                        ->label(__('Water'))
                        ->state(fn (QuotationRequest $record): string => $record->water_source?->label() ?? '—'),

                    TextEntry::make('address')
                        ->label(__('Address'))
                        ->placeholder('—')
                        ->columnSpanFull(),

                    TextEntry::make('additional_notes')
                        ->label(__('What they told us'))
                        ->placeholder(__('Nothing written'))
                        ->columnSpanFull(),

                    // Hidden rather than empty: a heading with nothing under it
                    // reads as a picture that failed to load.
                    ImageEntry::make('site_photos')
                        ->label(__('Site photographs'))
                        ->disk('public')
                        ->visible(fn (QuotationRequest $record): bool => filled($record->site_photos))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Our notes'))
                ->collapsed()
                ->schema([
                    TextEntry::make('outcome_note')
                        ->label(__('Outcome'))
                        ->placeholder('—')
                        ->columnSpanFull(),

                    TextEntry::make('admin_notes')
                        ->label(__('Notes'))
                        ->placeholder(__('Nothing recorded yet'))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
