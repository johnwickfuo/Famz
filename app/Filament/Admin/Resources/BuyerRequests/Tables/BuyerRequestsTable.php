<?php

namespace App\Filament\Admin\Resources\BuyerRequests\Tables;

use App\Enums\BuyerRequestStatus;
use App\Filament\Admin\Actions\BuyerRequestActions;
use App\Models\BuyerRequest;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BuyerRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['buyer', 'category'])->withCount('offers'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Wanted'))
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (BuyerRequest $record): string => $record->quantity.' '.$record->unit.' · '.$record->location())
                    ->wrap(),

                TextColumn::make('buyer.name')
                    ->label(__('Who'))
                    ->searchable()
                    ->visibleFrom('lg'),

                TextColumn::make('category.name')
                    ->label(__('Category'))
                    ->visibleFrom('xl'),

                TextColumn::make('offers_count')
                    ->label(__('Offers'))
                    ->alignRight()
                    ->visibleFrom('sm'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (BuyerRequestStatus $state): string => $state->label())
                    ->color(fn (BuyerRequestStatus $state): string => match ($state) {
                        BuyerRequestStatus::PendingApproval => 'warning',
                        BuyerRequestStatus::Open => 'success',
                        BuyerRequestStatus::OfferAccepted => 'info',
                        BuyerRequestStatus::Rejected => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Posted'))
                    ->since()
                    ->sortable()
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(BuyerRequestStatus::options()),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    ...BuyerRequestActions::all(),
                ])
                    ->label(__('What next?'))
                    ->tooltip(__('What next?')),
            ])
            ->emptyStateHeading(__('Nothing to check'))
            ->emptyStateDescription(__('Requests land here as soon as buyers post them.'));
    }
}
