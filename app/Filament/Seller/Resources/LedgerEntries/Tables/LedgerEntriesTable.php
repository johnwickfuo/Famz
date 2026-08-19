<?php

namespace App\Filament\Seller\Resources\LedgerEntries\Tables;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\WalletTransaction;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LedgerEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subOrder.order'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->date('j M Y')
                    ->description(fn (WalletTransaction $record): string => $record->created_at->format('H:i'))
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('What for'))
                    ->wrap()
                    ->searchable()
                    ->description(fn (WalletTransaction $record): ?string => $record->subOrder?->order?->reference),

                TextColumn::make('type')
                    ->label(__('Kind'))
                    ->badge()
                    ->formatStateUsing(fn (LedgerType $state): string => $state->label())
                    ->color(fn (LedgerType $state): string => match ($state) {
                        LedgerType::Sale, LedgerType::MentorshipEarning => 'success',
                        LedgerType::Withdrawal => 'info',
                        LedgerType::Reversal, LedgerType::Refund => 'danger',
                        default => 'gray',
                    })
                    ->visibleFrom('sm'),

                TextColumn::make('state')
                    ->label(__('Status'))
                    ->formatStateUsing(fn (LedgerState $state): string => $state->label())
                    ->visibleFrom('md'),

                TextColumn::make('amount_kobo')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '−').Money::fromKobo(abs($state)))
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->weight('bold')
                    ->alignRight()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Kind'))
                    ->multiple()
                    ->options(collect(LedgerType::cases())
                        ->mapWithKeys(fn (LedgerType $type): array => [$type->value => $type->label()])
                        ->all()),

                SelectFilter::make('state')
                    ->label(__('Status'))
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
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = __('From :date', ['date' => $data['from']]);
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = __('Until :date', ['date' => $data['until']]);
                        }

                        return $indicators;
                    }),
            ])
            ->emptyStateHeading(__('Nothing here yet'))
            ->emptyStateDescription(__('Every sale, payout and correction shows up here as it happens.'));
    }
}
