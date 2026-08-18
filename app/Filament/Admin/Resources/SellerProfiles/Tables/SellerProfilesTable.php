<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Tables;

use App\Enums\BusinessType;
use App\Enums\SellerStatus;
use App\Filament\Admin\Actions\SellerReviewActions;
use App\Models\SellerProfile;
use App\Support\Nigeria;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SellerProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Applications waiting on somebody come first; that is the whole
            // job of this screen.
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'categories']))
            ->columns([
                TextColumn::make('business_name')
                    ->label(__('Business'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (SellerProfile $record): string => $record->user?->email ?? ''),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (SellerStatus $state): string => $state->label())
                    ->color(fn (SellerStatus $state): string => match ($state) {
                        SellerStatus::Approved => 'success',
                        SellerStatus::Rejected => 'danger',
                        SellerStatus::NeedsMoreInfo => 'warning',
                        SellerStatus::Pending => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('business_type')
                    ->label(__('Type'))
                    ->formatStateUsing(fn (BusinessType $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('state')
                    ->label(__('Location'))
                    ->formatStateUsing(fn (?string $state, SellerProfile $record): string => $record->location())
                    ->searchable(['state', 'lga'])
                    ->sortable(),

                TextColumn::make('cac_number')
                    ->label(__('CAC'))
                    ->placeholder(__('Not registered'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('products_count')
                    ->label(__('Listings'))
                    ->counts('products')
                    ->alignRight()
                    ->sortable(),

                IconColumn::make('auto_approve_products')
                    ->label(__('Skips review'))
                    ->boolean()
                    ->placeholder('—')
                    ->tooltip(__('Empty means the platform rule applies: three approved listings and then automatically.'))
                    ->toggleable(),

                TextColumn::make('submitted_at')
                    ->label(__('Applied'))
                    ->dateTime('j M Y')
                    ->sortable(),

                TextColumn::make('reviewer.name')
                    ->label(__('Reviewed by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect(SellerStatus::cases())
                        ->mapWithKeys(fn (SellerStatus $status): array => [$status->value => $status->label()])
                        ->all()),

                SelectFilter::make('state')
                    ->label(__('State'))
                    ->options(array_combine(Nigeria::states(), Nigeria::states()))
                    ->searchable(),

                SelectFilter::make('business_type')
                    ->label(__('Business type'))
                    ->options(BusinessType::options()),

                TernaryFilter::make('cac_number')
                    ->label(__('CAC registered'))
                    ->nullable()
                    ->trueLabel(__('Registered'))
                    ->falseLabel(__('Not registered')),
            ])
            ->recordActions([
                ...SellerReviewActions::all(),
                ActionGroup::make([
                    EditAction::make(),
                ]),
            ]);
    }
}
