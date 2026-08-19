<?php

namespace App\Filament\Admin\Resources\LedgerEntries\Tables;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\WalletTransaction;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LedgerEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'subOrder.order']))
            ->columns([
                TextColumn::make('id')
                    ->label(__('#'))
                    ->sortable()
                    ->visibleFrom('2xl'),

                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label(__('Account'))
                    ->searchable()
                    ->limit(28)
                    // Null is a real account here, not a missing one.
                    ->placeholder(__('The platform'))
                    // The email on every row is noise; the Account filter is
                    // how you find a particular person.
                    ->tooltip(fn (WalletTransaction $record): ?string => $record->user?->email),

                TextColumn::make('description')
                    ->label(__('What for'))
                    ->searchable()
                    // Not wrapped: on a ledger the amount is the column that
                    // must never be pushed off the edge, and a description
                    // stacking onto four lines was doing exactly that.
                    ->limit(48)
                    ->tooltip(fn (WalletTransaction $record): string => $record->description)
                    // The buyer's order, not the seller's part — the part is
                    // already named in the description.
                    ->description(fn (WalletTransaction $record): ?string => $record->subOrder?->order?->reference),

                TextColumn::make('type')
                    ->label(__('Kind'))
                    ->badge()
                    ->formatStateUsing(fn (LedgerType $state): string => $state->label())
                    ->color(fn (LedgerType $state): string => match ($state) {
                        LedgerType::Sale, LedgerType::MentorshipEarning => 'success',
                        LedgerType::Commission => 'primary',
                        LedgerType::Withdrawal => 'info',
                        LedgerType::Reversal, LedgerType::Refund => 'danger',
                        default => 'gray',
                    })
                    ->visibleFrom('lg'),

                TextColumn::make('state')
                    ->label(__('State'))
                    ->formatStateUsing(fn (LedgerState $state): string => $state->label())
                    // Amount is the column a ledger exists for. Everything
                    // optional gives way to it before it does.
                    ->visibleFrom('2xl'),

                TextColumn::make('amount_kobo')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '−').Money::fromKobo(abs($state)))
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->weight('bold')
                    ->alignRight()
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label(__('Total'))
                            ->formatStateUsing(fn ($state): string => Money::fromKobo((int) $state))
                    ),
            ])
            ->filters([
                // Search by reference — the thing an administrator actually has
                // in front of them when somebody rings up about an order.
                Filter::make('reference')
                    ->schema([
                        TextInput::make('reference')
                            ->label(__('Order or payout reference'))
                            ->placeholder('ORD-… / SO-… / WDR-…'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['reference'] ?? null,
                        fn (Builder $q, string $reference) => $q
                            ->where(fn (Builder $inner) => $inner
                                ->whereHas('subOrder', fn (Builder $sub) => $sub->where('reference', 'like', "%{$reference}%"))
                                ->orWhereHas('subOrder.order', fn (Builder $order) => $order->where('reference', 'like', "%{$reference}%"))
                                ->orWhere('description', 'like', "%{$reference}%")),
                    ))
                    ->indicateUsing(fn (array $data): ?string => ($data['reference'] ?? null)
                        ? __('Reference: :value', ['value' => $data['reference']])
                        : null),

                SelectFilter::make('user_id')
                    ->label(__('Account'))
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('platform')
                    ->label(__('The platform\'s own account'))
                    ->placeholder(__('Everybody'))
                    ->trueLabel(__('Platform only'))
                    ->falseLabel(__('People only'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('user_id'),
                        false: fn (Builder $query) => $query->whereNotNull('user_id'),
                        blank: fn (Builder $query) => $query,
                    ),

                SelectFilter::make('type')
                    ->label(__('Kind'))
                    ->multiple()
                    ->options(collect(LedgerType::cases())
                        ->mapWithKeys(fn (LedgerType $type): array => [$type->value => $type->label()])
                        ->all()),

                SelectFilter::make('state')
                    ->label(__('State'))
                    ->multiple()
                    ->options(collect(LedgerState::cases())
                        ->mapWithKeys(fn (LedgerState $state): array => [$state->value => $state->label()])
                        ->all()),

                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading(__('Nothing matches'))
            ->emptyStateDescription(__('Try a wider date range, or search by an order reference.'));
    }
}
