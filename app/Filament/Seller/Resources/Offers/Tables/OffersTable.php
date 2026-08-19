<?php

namespace App\Filament\Seller\Resources\Offers\Tables;

use App\Enums\OfferStatus;
use App\Filament\Seller\Actions\OfferActions;
use App\Models\BuyerRequest;
use App\Models\Offer;
use App\Models\Product;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OffersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Oldest first: whoever has waited longest for an answer gets one.
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['offerable', 'initiator', 'parent']))
            ->columns([
                TextColumn::make('offerable')
                    ->label(__('About'))
                    ->formatStateUsing(fn ($state): string => match (true) {
                        $state instanceof Product => $state->name,
                        $state instanceof BuyerRequest => $state->title,
                        default => __('Something that has since been removed'),
                    })
                    ->description(fn (Offer $record): string => $record->offerable instanceof BuyerRequest
                        ? __('A buyer\'s request')
                        : __('Your listing'))
                    ->wrap(),

                TextColumn::make('initiator.name')
                    ->label(__('From'))
                    ->description(fn (Offer $record): string => $record->round() > 1
                        ? __('Round :n', ['n' => $record->round()])
                        : __('First offer'))
                    ->visibleFrom('sm'),

                TextColumn::make('quantity')
                    ->label(__('How many'))
                    ->alignRight()
                    ->visibleFrom('md'),

                TextColumn::make('unit_price_kobo')
                    ->label(__('Their price'))
                    ->formatStateUsing(fn (int $state): string => Money::fromKobo($state))
                    ->description(fn (Offer $record): string => __(':total in total', [
                        'total' => $record->totalPrice(),
                    ]))
                    ->weight('bold')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (OfferStatus $state): string => $state->label())
                    ->color(fn (OfferStatus $state): string => match ($state) {
                        OfferStatus::Pending => 'warning',
                        OfferStatus::Accepted => 'success',
                        OfferStatus::Countered => 'info',
                        OfferStatus::Rejected => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label(__('Answer by'))
                    ->since()
                    ->placeholder(__('No deadline'))
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(OfferStatus::options()),
            ])
            ->recordActions([
                ActionGroup::make(OfferActions::all())
                    ->label(__('What next?'))
                    ->tooltip(__('What next?')),
            ])
            ->emptyStateHeading(__('No offers yet'))
            ->emptyStateDescription(__('Offers on your listings, and on requests you have answered, show up here.'));
    }
}
