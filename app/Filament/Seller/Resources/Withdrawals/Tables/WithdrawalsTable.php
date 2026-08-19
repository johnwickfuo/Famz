<?php

namespace App\Filament\Seller\Resources\Withdrawals\Tables;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WithdrawalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('payoutAccount'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('Payout'))
                    ->searchable()
                    ->weight('bold')
                    // On a phone the amount rides under the reference and the
                    // column below is hidden: a seventeen-character reference
                    // plus an amount plus a status does not fit in 360px, and
                    // the status is what a seller opened the page to read.
                    ->description(fn (Withdrawal $record): string => Money::fromKobo($record->amount_kobo)),

                TextColumn::make('amount_kobo')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state): string => Money::fromKobo($state))
                    ->weight('bold')
                    ->alignRight()
                    ->sortable()
                    ->visibleFrom('sm'),

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
                    // The date rides along here rather than under the
                    // reference: at 360px three columns plus a two-line first
                    // column pushed the status — the thing a seller opens this
                    // page to read — off the edge.
                    ->description(fn (Withdrawal $record): string => $record->failure_reason
                        ?? $record->admin_note
                        ?? __('Asked :date', ['date' => $record->created_at->format('j M')]))
                    ->wrap()
                    ->sortable(),

                TextColumn::make('payoutAccount.bank_name')
                    ->label(__('To'))
                    ->description(fn (Withdrawal $record): ?string => $record->payoutAccount?->maskedNumber())
                    ->visibleFrom('sm'),

                TextColumn::make('processed_at')
                    ->label(__('Sent'))
                    ->since()
                    ->placeholder(__('Not yet'))
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(WithdrawalStatus::options()),
            ])
            ->emptyStateHeading(__('No payouts yet'))
            ->emptyStateDescription(__('Money you take out of your balance shows up here.'));
    }
}
