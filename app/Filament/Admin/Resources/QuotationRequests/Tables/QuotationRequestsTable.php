<?php

namespace App\Filament\Admin\Resources\QuotationRequests\Tables;

use App\Enums\QuotationProjectType;
use App\Enums\QuotationRequestStatus;
use App\Enums\StudyFeeCreditStatus;
use App\Models\QuotationRequest;
use App\Services\Quotations\QuotationService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class QuotationRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'studyFee', 'currentQuotation']))
            /*
             * Paid work is tinted. The distinction that matters on this screen
             * is not which status a row is in but whether the company has
             * already taken money for it — that is the row somebody owes a
             * proposal, and a colour is read before a word is.
             */
            ->recordClasses(fn (QuotationRequest $record): ?string => match (true) {
                $record->isWorkable() => 'bg-warning-50 dark:bg-warning-950/30',
                default => null,
            })
            ->columns([
                TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->searchable(['reference'])
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('user.name')
                    ->label(__('Who'))
                    ->description(fn (QuotationRequest $record): ?string => $record->user?->email)
                    ->searchable(['users.name', 'users.email'])
                    ->wrap(),

                TextColumn::make('project_type')
                    ->label(__('Project'))
                    ->badge()
                    ->formatStateUsing(fn (QuotationProjectType $state): string => $state->shortLabel())
                    ->description(fn (QuotationRequest $record): string => $record->farm_type),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (QuotationRequestStatus $state): string => $state->adminLabel())
                    ->color(fn (QuotationRequestStatus $state): string => $state->filamentColour()),

                /*
                 * The audit column. Prominent on the queue as well as the
                 * record, because the question this answers — "was that fee
                 * ever credited?" — is asked months later by somebody who does
                 * not know which request to open.
                 */
                TextColumn::make('studyFee.credit_status')
                    ->label(__('Study fee'))
                    ->badge()
                    ->placeholder(__('Not raised'))
                    ->formatStateUsing(fn (StudyFeeCreditStatus $state, QuotationRequest $record): string => $record->studyFeePaid()
                        ? $state->shortLabel()
                        : __('Unpaid'))
                    ->color(fn (StudyFeeCreditStatus $state, QuotationRequest $record): string => $record->studyFeePaid()
                        ? $state->filamentColour()
                        : 'danger')
                    ->visibleFrom('md'),

                /*
                 * Late to appear, because on this queue it is mostly a dash: a
                 * total exists only once a proposal has gone out, and the rows
                 * that need working have not had one written yet.
                 */
                TextColumn::make('currentQuotation.total_kobo')
                    ->label(__('Quoted'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Money::fromKobo($state))
                    ->alignRight()
                    ->visibleFrom('2xl'),

                TextColumn::make('created_at')
                    ->label(__('Asked'))
                    ->dateTime('j M Y')
                    ->sortable()
                    ->visibleFrom('2xl'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(QuotationRequestStatus::options()),

                SelectFilter::make('project_type')
                    ->label(__('Project'))
                    ->options(QuotationProjectType::options()),

                Filter::make('workable')
                    ->label(__('Paid and waiting'))
                    ->query(fn (Builder $query) => $query->workable()),

                Filter::make('uncredited')
                    ->label(__('Fee never decided'))
                    ->query(fn (Builder $query) => $query->whereHas(
                        'studyFee',
                        fn (Builder $fee) => $fee->whereNotNull('paid_at')->whereNull('credited_at'),
                    )),
            ])
            ->recordActions([
                ViewAction::make()->button(),

                ActionGroup::make([
                    Action::make('accept')
                        ->label(__('Mark as won'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        // Reporting only. There is no project tracking after
                        // this and there is not meant to be: the build happens
                        // between the client and the company.
                        ->modalDescription(__('For reporting only. The build itself is run offline.'))
                        ->visible(fn (QuotationRequest $record): bool => ! $record->isClosed())
                        ->schema([
                            Textarea::make('note')
                                ->label(__('Anything worth recording'))
                                ->rows(3)
                                ->maxLength(1000),
                        ])
                        ->action(fn (QuotationRequest $record, array $data) => self::close(
                            $record,
                            QuotationRequestStatus::AcceptedOffline,
                            $data['note'] ?? null,
                        )),

                    Action::make('decline')
                        ->label(__('Mark as lost'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (QuotationRequest $record): bool => ! $record->isClosed())
                        ->schema([
                            Textarea::make('note')
                                ->label(__('Why, if you know'))
                                ->rows(3)
                                ->maxLength(1000)
                                ->helperText(__('Worth writing down. Lost reasons are the only feedback on pricing this company gets.')),
                        ])
                        ->action(fn (QuotationRequest $record, array $data) => self::close(
                            $record,
                            QuotationRequestStatus::Declined,
                            $data['note'] ?? null,
                        )),

                    Action::make('reopen')
                        ->label(__('Reopen'))
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->requiresConfirmation()
                        ->visible(fn (QuotationRequest $record): bool => $record->isClosed())
                        ->action(function (QuotationRequest $record): void {
                            app(QuotationService::class)->reopen($record);

                            Notification::make()->title(__('Reopened'))->success()->send();
                        }),
                ]),
            ]);
    }

    private static function close(QuotationRequest $record, QuotationRequestStatus $outcome, ?string $note): void
    {
        try {
            app(QuotationService::class)->close($record, $outcome, auth()->user(), $note);
        } catch (RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($outcome === QuotationRequestStatus::AcceptedOffline ? __('Recorded as won') : __('Recorded as lost'))
            ->success()
            ->send();
    }
}
