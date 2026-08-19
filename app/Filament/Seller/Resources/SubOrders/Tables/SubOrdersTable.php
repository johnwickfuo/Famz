<?php

namespace App\Filament\Seller\Resources\SubOrders\Tables;

use App\Enums\DeliveryMethod;
use App\Enums\SubOrderStatus;
use App\Filament\Seller\Actions\FulfilmentActions;
use App\Models\SubOrder;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first: the buyer who has waited longest is served first.
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order', 'items']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('Order'))
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (SubOrder $record): string => $record->order->reference),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (SubOrderStatus $state): string => $state->label())
                    ->color(fn (SubOrderStatus $state): string => match ($state) {
                        SubOrderStatus::Pending => 'warning',
                        SubOrderStatus::Accepted, SubOrderStatus::Shipped => 'info',
                        SubOrderStatus::Delivered, SubOrderStatus::Settled => 'success',
                        SubOrderStatus::Rejected, SubOrderStatus::Disputed, SubOrderStatus::Refunded => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label(__('Items'))
                    ->counts('items')
                    ->alignRight()
                    ->visibleFrom('sm'),

                TextColumn::make('subtotal_kobo')
                    ->label(__('Goods'))
                    ->formatStateUsing(fn (int $state): string => Money::fromKobo($state))
                    ->alignRight()
                    ->sortable()
                    ->visibleFrom('md'),

                // What the seller actually receives, after the platform's cut.
                TextColumn::make('seller_payout_amount_kobo')
                    ->label(__('You receive'))
                    ->formatStateUsing(fn (int $state): string => Money::fromKobo($state))
                    ->description(fn (SubOrder $record): string => __('after :percent% commission', [
                        'percent' => rtrim(rtrim((string) $record->commission_percent_snapshot, '0'), '.'),
                    ]))
                    ->alignRight()
                    ->weight('bold')
                    ->visibleFrom('sm'),

                TextColumn::make('delivery_method')
                    ->label(__('Delivery'))
                    ->formatStateUsing(fn (DeliveryMethod $state): string => $state->label())
                    ->description(fn (SubOrder $record): ?string => $record->delivery_fee_kobo > 0
                        ? Money::fromKobo($record->delivery_fee_kobo)
                        : null)
                    ->toggleable()
                    ->visibleFrom('lg'),

                TextColumn::make('order.delivery_state')
                    ->label(__('To'))
                    ->formatStateUsing(fn (?string $state, SubOrder $record): string => trim(
                        $record->order->delivery_lga.', '.$record->order->delivery_state, ', '
                    ))
                    ->toggleable()
                    ->visibleFrom('lg'),

                TextColumn::make('created_at')
                    ->label(__('Placed'))
                    ->since()
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(SubOrderStatus::options()),

                SelectFilter::make('delivery_method')
                    ->label(__('Delivery'))
                    ->options(collect(DeliveryMethod::cases())
                        ->mapWithKeys(fn (DeliveryMethod $method): array => [$method->value => $method->label()])
                        ->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    ...FulfilmentActions::all(),
                    ViewAction::make(),
                ])
                    ->label(__('What next?'))
                    ->tooltip(__('What next?')),
            ])
            ->emptyStateHeading(__('No orders yet'))
            ->emptyStateDescription(__('Orders from buyers will appear here as soon as they pay.'));
    }
}
