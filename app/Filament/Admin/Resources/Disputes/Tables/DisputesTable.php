<?php

namespace App\Filament\Admin\Resources\Disputes\Tables;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Filament\Admin\Actions\DisputeActions;
use App\Models\Dispute;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first: a dispute nobody has touched is somebody's money
            // sitting still.
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'subOrder.seller', 'subOrder.order.user', 'raiser',
            ]))
            ->columns([
                TextColumn::make('subOrder.reference')
                    ->label(__('Order'))
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Dispute $record): string => $record->created_at->format('j M Y')),

                TextColumn::make('reason')
                    ->label(__('Complaint'))
                    ->formatStateUsing(fn (DisputeReason $state): string => $state->label())
                    ->description(fn (Dispute $record): string => str($record->description)->limit(70)->value())
                    ->wrap(),

                TextColumn::make('raiser.name')
                    ->label(__('Buyer'))
                    ->searchable()
                    ->visibleFrom('lg'),

                TextColumn::make('subOrder.seller.business_name')
                    ->label(__('Seller'))
                    ->searchable()
                    ->visibleFrom('lg'),

                TextColumn::make('subOrder.subtotal_kobo')
                    ->label(__('At stake'))
                    ->formatStateUsing(fn ($state, Dispute $record): string => Money::fromKobo(
                        $record->amountAtStakeKobo()
                    ))
                    ->description(fn (Dispute $record): ?string => $record->refund_amount_kobo > 0
                        ? __(':amount refunded', ['amount' => Money::fromKobo($record->refund_amount_kobo)])
                        : null)
                    ->alignRight()
                    ->visibleFrom('sm'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (DisputeStatus $state): string => $state->label())
                    ->color(fn (DisputeStatus $state): string => match ($state) {
                        DisputeStatus::Open => 'danger',
                        DisputeStatus::UnderReview => 'warning',
                        DisputeStatus::Closed => 'gray',
                        default => 'success',
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(DisputeStatus::options()),

                SelectFilter::make('reason')
                    ->label(__('Complaint'))
                    ->multiple()
                    ->options(DisputeReason::options()),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    ...DisputeActions::all(),
                ])
                    ->label(__('What next?'))
                    ->tooltip(__('What next?')),
            ])
            ->emptyStateHeading(__('No disputes'))
            ->emptyStateDescription(__('Complaints from buyers land here, with the money already frozen.'));
    }
}
