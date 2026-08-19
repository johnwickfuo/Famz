<?php

namespace App\Filament\Admin\Resources\Withdrawals\Tables;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Services\Payouts\WithdrawalService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class WithdrawalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first: whoever has waited longest gets their money first.
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user.profile', 'payoutAccount']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('Payout'))
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Withdrawal $record): string => $record->created_at->format('j M Y, H:i')),

                TextColumn::make('user.name')
                    ->label(__('Who'))
                    ->searchable()
                    ->description(fn (Withdrawal $record): ?string => $record->user?->email)
                    ->wrap(),

                TextColumn::make('amount_kobo')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state): string => Money::fromKobo($state))
                    ->weight('bold')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (WithdrawalStatus $state): string => $state->label())
                    ->color(fn (WithdrawalStatus $state): string => match ($state) {
                        WithdrawalStatus::Paid => 'success',
                        WithdrawalStatus::Processing, WithdrawalStatus::Approved => 'info',
                        WithdrawalStatus::Requested => 'warning',
                        WithdrawalStatus::Failed, WithdrawalStatus::Rejected => 'danger',
                    })
                    ->description(fn (Withdrawal $record): ?string => $record->failure_reason)
                    ->sortable(),

                TextColumn::make('payoutAccount.bank_name')
                    ->label(__('To'))
                    ->description(fn (Withdrawal $record): ?string => trim(
                        ($record->payoutAccount?->account_name ?? '').' '.($record->payoutAccount?->maskedNumber() ?? '')
                    ))
                    // A long bank name plus an account name pushed the actions
                    // menu off the right-hand edge at 1280px, which is where
                    // the approve button lives.
                    ->visibleFrom('2xl'),

                TextColumn::make('processed_at')
                    ->label(__('Sent'))
                    ->since()
                    ->placeholder(__('Not yet'))
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    // No default: the tabs above already narrow this, and two
                    // things filtering the same column is how a row goes
                    // missing without anybody knowing why.
                    ->options(WithdrawalStatus::options()),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::approveAndSend(),
                    self::approveOnly(),
                    self::reject(),
                    self::markPaid(),
                    self::markFailed(),
                ])
                    ->label(__('What next?'))
                    ->tooltip(__('What next?')),
            ])
            ->emptyStateHeading(__('No payouts'))
            ->emptyStateDescription(__('Requests from sellers appear here as soon as they are made.'));
    }

    /**
     * The normal path: agree to it and hand it to the gateway in one step.
     */
    private static function approveAndSend(): Action
    {
        return Action::make('approveAndSend')
            ->label(__('Approve and send'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Send this payout?'))
            ->modalDescription(fn (Withdrawal $record): string => __('The money leaves the platform account now and goes to :name at :bank.', [
                'name' => $record->payoutAccount?->account_name ?? '—',
                'bank' => $record->payoutAccount?->bank_name ?? '—',
            ]))
            ->visible(fn (Withdrawal $record): bool => in_array(
                $record->status,
                [WithdrawalStatus::Requested, WithdrawalStatus::Approved],
                true,
            ))
            ->action(function (Withdrawal $record): void {
                $service = app(WithdrawalService::class);

                self::run(function () use ($service, $record): void {
                    if ($record->status === WithdrawalStatus::Requested) {
                        $service->approve($record, auth()->user());
                    }

                    $result = $service->process($record->fresh(), auth()->user());

                    if ($result->status === WithdrawalStatus::Failed) {
                        throw new RuntimeException(
                            $result->failure_reason ?? __('The provider would not send this.')
                        );
                    }
                }, __('Payout sent'));
            });
    }

    private static function approveOnly(): Action
    {
        return Action::make('approveWithdrawal')
            ->label(__('Approve, send later'))
            ->icon('heroicon-o-check')
            ->visible(fn (Withdrawal $record): bool => $record->status === WithdrawalStatus::Requested)
            ->schema([
                Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(fn (Withdrawal $record, array $data) => self::run(
                fn () => app(WithdrawalService::class)->approve($record, auth()->user(), $data['note'] ?? null),
                __('Approved'),
            ));
    }

    private static function reject(): Action
    {
        return Action::make('rejectWithdrawal')
            ->label(__('Turn down'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading(__('Turn down this payout'))
            ->modalDescription(__('The money goes straight back into their balance.'))
            ->visible(fn (Withdrawal $record): bool => ! $record->status->isFinished()
                && $record->wallet_transaction_id === null)
            ->schema([
                Placeholder::make('amount')
                    ->label(__('Amount'))
                    ->content(fn (Withdrawal $record): string => Money::fromKobo($record->amount_kobo)),

                Textarea::make('reason')
                    ->label(__('Why?'))
                    // The seller reads this, so it has to say something.
                    ->helperText(__('The seller sees this exactly as you write it.'))
                    ->rows(3)
                    ->required()
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->action(fn (Withdrawal $record, array $data) => self::run(
                fn () => app(WithdrawalService::class)->reject($record, auth()->user(), $data['reason']),
                __('Turned down and the balance restored'),
            ));
    }

    /**
     * For a transfer confirmed outside the application — a bank statement, a
     * gateway dashboard, a manual payment.
     */
    private static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark as landed'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Withdrawal $record): bool => $record->status === WithdrawalStatus::Processing)
            ->action(fn (Withdrawal $record) => self::run(
                fn () => app(WithdrawalService::class)->markPaid($record),
                __('Marked as paid'),
            ));
    }

    private static function markFailed(): Action
    {
        return Action::make('markFailed')
            ->label(__('It bounced'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->modalDescription(__('The amount goes back into their balance, with a reversal entry beside the original.'))
            ->visible(fn (Withdrawal $record): bool => $record->status === WithdrawalStatus::Processing)
            ->schema([
                Textarea::make('reason')
                    ->label(__('What did the bank say?'))
                    ->rows(2)
                    ->required()
                    ->maxLength(500),
            ])
            ->action(fn (Withdrawal $record, array $data) => self::run(
                fn () => app(WithdrawalService::class)->markFailed($record, $data['reason']),
                __('Recorded, and the balance restored'),
            ));
    }

    private static function run(callable $operation, string $title): void
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($title)->success()->send();
    }
}
