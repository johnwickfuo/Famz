<?php

namespace App\Filament\Admin\Resources\Consultations\Tables;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Models\Consultation;
use App\Services\Consultations\ConsultationNotifier;
use App\Services\Consultations\ConsultationService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ConsultationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Soonest deadline first, and nulls last. This is the single most
             * important line on the screen: it is what turns a list into a
             * queue, and what makes "who do I ring next" answerable without
             * reading anything.
             */
            ->defaultSort('response_due_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'publishedReport']))
            /*
             * Overdue rows are tinted, not just badged. A colour is read before
             * a word is, and somebody scanning this at speed should not have to
             * parse a column to see that four people are still waiting.
             */
            ->recordClasses(fn (Consultation $record): ?string => match (true) {
                $record->isOverdue() => 'bg-danger-50 dark:bg-danger-950/40',
                $record->isDueSoon() => 'bg-warning-50 dark:bg-warning-950/30',
                default => null,
            })
            /*
             * Ordered by what somebody working this screen needs, in the order
             * they need it: how late it is, who to ring, whether it is urgent.
             * The reference is what a client reads out on the phone, not what
             * an administrator scans for, so it sits behind those three.
             */
            ->columns([
                /*
                 * The deadline, said in the way a person needs it: "2 hours
                 * late" rather than a timestamp they have to subtract from now.
                 */
                TextColumn::make('response_due_at')
                    ->label(__('Response due'))
                    ->state(fn (Consultation $record): string => match (true) {
                        $record->first_responded_at !== null => __('Answered'),
                        $record->response_due_at === null => '—',
                        $record->isOverdue() => __(':for late', ['for' => $record->response_due_at->diffForHumans(syntax: true)]),
                        default => $record->response_due_at->diffForHumans(),
                    })
                    ->description(fn (Consultation $record): ?string => $record->response_due_at?->format('j M, H:i'))
                    ->badge()
                    ->color(fn (Consultation $record): string => match (true) {
                        $record->first_responded_at !== null => 'success',
                        $record->isOverdue() => 'danger',
                        $record->isDueSoon() => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('full_name')
                    ->label(__('Who'))
                    ->description(fn (Consultation $record): string => $record->phone)
                    // Includes the reference, so searching still works on a
                    // narrow screen where that column is hidden.
                    ->searchable(['full_name', 'phone', 'email', 'reference'])
                    ->wrap(),

                TextColumn::make('tier')
                    ->label(__('Service'))
                    ->badge()
                    ->formatStateUsing(fn (ConsultationTier $state): string => $state->label())
                    ->color(fn (ConsultationTier $state): string => $state->isUrgent() ? 'danger' : 'gray'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (ConsultationStatus $state): string => $state->adminLabel())
                    ->color(fn (ConsultationStatus $state): string => $state->filamentColour())
                    ->visibleFrom('md'),

                TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->searchable()
                    ->copyable()
                    ->weight('bold')
                    ->visibleFrom('lg'),

                /*
                 * Late in the order and late to appear, because in the queue
                 * this column is mostly a dash: a price exists only after
                 * somebody has rung, and the rows that need working have not
                 * been rung yet.
                 */
                TextColumn::make('quoted_amount_kobo')
                    ->label(__('Quote'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Money::fromKobo($state))
                    ->alignRight()
                    ->visibleFrom('2xl'),

                /*
                 * Truncated rather than wrapped. Wrapped, this column competes
                 * with every other one for width and loses, and a paragraph
                 * broken to one word a line is harder to read than no paragraph
                 * at all — the whole of it is one hover away, and one click away
                 * on the record itself.
                 */
                TextColumn::make('situation')
                    ->label(__('The problem'))
                    ->placeholder(__('Nothing written — ring them'))
                    ->limit(60)
                    ->tooltip(fn (Consultation $record): ?string => $record->situation)
                    ->visibleFrom('2xl'),
            ])
            ->filters([
                SelectFilter::make('tier')->label(__('Service'))->options(ConsultationTier::options()),

                Filter::make('overdue')
                    ->label(__('Late only'))
                    ->query(fn (Builder $query) => $query->overdue()),

                Filter::make('unanswered')
                    ->label(__('Never answered'))
                    ->query(fn (Builder $query) => $query->whereNull('first_responded_at')),
            ])
            ->recordActions([
                /*
                 * The first action, because it is the one that keeps the
                 * promise. Everything else on this screen can wait; ringing
                 * somebody back cannot.
                 */
                Action::make('recordContact')
                    ->label(__('Record contact'))
                    ->icon('heroicon-o-phone')
                    ->color('success')
                    ->button()
                    ->visible(fn (Consultation $record): bool => $record->first_responded_at === null
                        && ! $record->status->isFinished())
                    ->modalHeading(__('You spoke to them'))
                    ->modalSubmitActionLabel(__('Record it'))
                    ->schema([
                        Placeholder::make('promise')
                            ->label(__('We promised'))
                            ->content(fn (Consultation $record): string => $record->response_due_at?->format('j M Y, H:i') ?? '—'),

                        Textarea::make('note')
                            ->label(__('What was said'))
                            ->rows(4)
                            ->maxLength(2000)
                            ->helperText(__('Kept on the consultation with your name and the time. The client does not see it.')),
                    ])
                    ->action(function (Consultation $record, array $data): void {
                        try {
                            app(ConsultationService::class)->recordContact($record, auth()->user(), $data['note'] ?? null);
                        } catch (RuntimeException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Contact recorded'))
                            ->body(__('The response clock has stopped on this one.'))
                            ->success()
                            ->send();
                    }),

                ActionGroup::make([
                    ViewAction::make(),

                    Action::make('quote')
                        ->label(fn (Consultation $record): string => $record->isQuoted()
                            ? __('Change the price')
                            : __('Set a price'))
                        ->icon('heroicon-o-banknotes')
                        ->visible(fn (Consultation $record): bool => ! $record->isPaid()
                            && ! $record->status->isFinished())
                        ->modalHeading(__('Set a price'))
                        ->modalDescription(__('This emails them a link to pay and shows it on their dashboard.'))
                        ->modalSubmitActionLabel(__('Send the price'))
                        ->schema([
                            TextInput::make('amount')
                                ->label(__('Price'))
                                ->prefix(Money::SIGN)
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                // There is no price list. This is a number a
                                // person decided after a conversation.
                                ->helperText(__('Whatever you agreed on the call. There is no fixed rate.'))
                                ->default(fn (Consultation $record): ?float => $record->quoted_amount_kobo === null
                                    ? null
                                    : $record->quoted_amount_kobo / 100),

                            Textarea::make('note')
                                ->label(__('What it covers'))
                                ->rows(3)
                                ->maxLength(1000)
                                ->helperText(__('Shown to the client beside the price. Say what they are getting.')),
                        ])
                        ->action(function (Consultation $record, array $data): void {
                            try {
                                $quoted = app(ConsultationService::class)->quote(
                                    $record,
                                    auth()->user(),
                                    Money::toKobo($data['amount']),
                                    $data['note'] ?? null,
                                );
                            } catch (RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            app(ConsultationNotifier::class)->quoted($quoted);

                            Notification::make()
                                ->title(__('Price sent'))
                                ->body(__(':amount. They have been emailed a link to pay.', [
                                    'amount' => $quoted->quotedAmount(),
                                ]))
                                ->success()
                                ->send();
                        }),

                    Action::make('start')
                        ->label(__('Start the work'))
                        ->icon('heroicon-o-play')
                        ->visible(fn (Consultation $record): bool => $record->status === ConsultationStatus::Paid)
                        ->requiresConfirmation()
                        ->action(function (Consultation $record): void {
                            try {
                                app(ConsultationService::class)->start($record);
                            } catch (RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()->title(__('Under way'))->success()->send();
                        }),

                    Action::make('complete')
                        ->label(__('Mark finished'))
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn (Consultation $record): bool => $record->status->isLive())
                        ->requiresConfirmation()
                        ->modalDescription(fn (): string => __(
                            'The follow-up thread stays open for :days days afterwards.',
                            ['days' => (int) settings('consultation_followup_days', 30)],
                        ))
                        ->action(function (Consultation $record): void {
                            try {
                                app(ConsultationService::class)->complete($record);
                            } catch (RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()->title(__('Finished'))->success()->send();
                        }),

                    Action::make('cancel')
                        ->label(__('Cancel'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Consultation $record): bool => ! $record->isPaid()
                            && ! $record->status->isFinished())
                        ->schema([
                            Textarea::make('reason')
                                ->label(__('Why'))
                                ->required()
                                ->rows(3)
                                ->maxLength(500),
                        ])
                        ->action(function (Consultation $record, array $data): void {
                            try {
                                app(ConsultationService::class)->cancel($record, $data['reason'], auth()->user());
                            } catch (RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()->title(__('Cancelled'))->success()->send();
                        }),
                ]),
            ]);
    }
}
